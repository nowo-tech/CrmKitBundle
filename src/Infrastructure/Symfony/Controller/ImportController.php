<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportConfirmType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportUploadType;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function file_get_contents;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Imports (contract sections 9 and 15.4): upload ⇒ preview with the mapping ⇒ confirm (one claim wins) ⇒ run in the
 * request up to `import.sync_rows`, then "continue" or the resume command. Stateless.
 */
final readonly class ImportController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private ImportServiceInterface $imports,
        private RecordForms $forms,
        private int $syncChunks = 5,
        private int $maxBytes = 5242880,
    ) {
    }

    /**
     * The upload form.
     */
    public function index(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Import);
            $form = $this->ui->form(ImportUploadType::class, null, ['max_bytes' => $this->maxBytes], 'crm_import');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $data = is_array($data) ? $data : [];
                $file = $data['file'] ?? null;
                try {
                    $type   = SubjectType::from(is_string($data['subject'] ?? null) ? $data['subject'] : 'contact');
                    $format = ImportFormat::from(is_string($data['format'] ?? null) ? $data['format'] : 'csv');
                    // FileType and its File(maxSize) constraint only let a valid upload through; the size is checked
                    // again before the file is read into memory (`import.max_bytes`).
                    $id = $file instanceof UploadedFile ? ($file->getSize() <= $this->maxBytes ? $this->imports->upload($type, $format, $file->getClientOriginalName(), (string) file_get_contents($file->getPathname())) : throw new ImportFileException('import.error.too_large')) : throw new ImportFileException('import.error.read');

                    return $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
                } catch (ImportFileException $e) {
                    $form->get('file')->addError(new FormError($this->ui->trans($e->getMessage()))); // @igor-ignore - a change of a per-call entity or record; nothing is kept on the service
                }
            }

            return $this->ui->render('@NowoCrmKit/import/upload.html.twig', [
                'page' => new FormPage(
                    new PageHeader(UiSupport::label('import.title'), UiSupport::label('import.intro'), [], tabs: $this->ui->tabs('import')),
                    $form->createView(),
                    UiSupport::label('import.upload'),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-import-upload'],
                    formAttributes: ['enctype' => 'multipart/form-data'],
                ),
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * Preview and confirm; continue a running import; cancel.
     */
    public function show(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import  = $this->imports->preview($id);
            $targets = ['import.target.skip' => ''];
            foreach (RecordFields::names($import->subjectType) as $field) {
                $targets['field.' . $field] = $field;
            }
            if ($import->subjectType === SubjectType::Contact) {
                $targets['import.target.company']         = 'company';
                $targets['import.target.purposes']        = 'purposes';
                $targets['import.target.primary_contact'] = 'primary_contact';
            }
            $targets['import.target.tags']  = 'tags';
            $targets['import.target.roles'] = 'roles';
            foreach ($this->forms->fields($import->subjectType) as $field) {
                $targets[$field->label . ' (' . $field->key . ')'] = 'custom.' . $field->key;
            }
            $data = ['policy' => $import->duplicatePolicy->value, 'version' => (string) $import->version];
            foreach ($import->columns as $i => $column) {
                $data['col_' . $i] = $import->mapping[$column] ?? '';
            }
            $form   = $this->ui->form(ImportConfirmType::class, $data, ['columns' => $import->columns, 'targets' => $targets], 'crm_import_confirm');
            $action = $this->ui->form(ActionType::class, null, [], 'crm_action');
            $status = Response::HTTP_OK;
            if ($request->isMethod('POST')) {
                $do = (string) $request->request->get('do', 'confirm');
                try {
                    if ($do === 'run' || $do === 'cancel') {
                        $action->handleRequest($request);
                        if (!$action->isSubmitted() || !$action->isValid()) {
                            throw InvalidInputException::field('_', 'error.csrf');
                        }
                        if ($do === 'cancel') {
                            $this->imports->cancel($id);
                            $this->ui->flash($request, 'success', 'import.cancelled');
                        } else {
                            $this->imports->run($id, $this->syncChunks);
                        }

                        return $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
                    }
                    $form->handleRequest($request);
                    if ($form->isSubmitted() && $form->isValid()) {
                        $values  = $form->getData();
                        $values  = is_array($values) ? $values : [];
                        $mapping = [];
                        foreach ($import->columns as $i => $column) {
                            $mapping[$column] = is_string($values['col_' . $i] ?? null) ? $values['col_' . $i] : '';
                        }
                        $this->imports->confirm($id, $mapping, DuplicatePolicy::tryFrom(is_string($values['policy'] ?? null) ? $values['policy'] : '') ?? DuplicatePolicy::Skip, is_numeric($values['version'] ?? null) ? (int) $values['version'] : -1);
                        $done = $this->imports->run($id, $this->syncChunks);
                        $this->ui->flash($request, 'success', $done ? 'import.done' : 'import.running');

                        return $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
                    }
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } catch (ConflictException $e) {
                    $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                    return $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
                } catch (InvalidInputException $e) {
                    $this->ui->errors($form, $e);
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                }
            }

            return $this->ui->render('@NowoCrmKit/import/show.html.twig', [
                'page' => new FormPage(
                    new PageHeader(UiSupport::label('import.show_title', ['%file%' => $import->fileName]), UiSupport::label('import.status.' . $import->status->value), [], tabs: $this->ui->tabs('import')),
                    $import->status === ImportStatus::Preview ? $form->createView() : null,
                    UiSupport::label('import.confirm'),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-import', 'data-status' => $import->status->value],
                ),
                'import' => $import,
                'action' => $action->createView(),
            ], $status);
        });
    }
}
