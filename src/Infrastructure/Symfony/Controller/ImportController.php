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
use Nowo\CrmKitBundle\Domain\Read\ImportView;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportConfirmType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportUploadType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ImportDialog;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function file_get_contents;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function reset;

/**
 * Imports (contract sections 9 and 15.4): upload ⇒ preview with the mapping ⇒ confirm (one claim wins) ⇒ run in the
 * request up to `import.sync_rows`, then "continue" or the resume command. 0.4.0: the same routes answer the import
 * modal of the lists ({@see ImportDialog}): a step fragment (file ⇒ mapping ⇒ preview ⇒ result) instead of the page,
 * 204 with the next step's address instead of a redirect, errors in place; same access checks and CSRF tokens. The
 * pages stay the no-JS fallback. Stateless.
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
            $dialog  = ImportDialog::requested($request);
            $subject = $request->query->get('subject');
            $form    = $this->ui->form(ImportUploadType::class, in_array($subject, ['contact', 'company'], true) ? ['subject' => $subject] : null, ['max_bytes' => $this->maxBytes], 'crm_import');
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

                    return $dialog ? ImportDialog::next($this->ui->url('nowo_crm_kit_import_show', ['id' => $id])) : $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
                } catch (ImportFileException $e) {
                    $form->get('file')->addError(new FormError($this->ui->trans($e->getMessage()))); // @igor-ignore - a change of a per-call entity or record; nothing is kept on the service
                }
            }

            $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($dialog) {
                return $this->step('file', $status, ['form' => $form->createView(), 'subject' => in_array($subject, ['contact', 'company'], true) ? $subject : null]);
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
            ], $status, ImportDialog::privateHeaders());
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
            $dialog = ImportDialog::requested($request);
            $status = Response::HTTP_OK;
            $view   = $import;
            $step   = $import->status === ImportStatus::Preview ? 'mapping' : 'result';
            $alert  = null;
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
                            if (!$dialog) {
                                $this->ui->flash($request, 'success', 'import.cancelled');
                            }
                        } else {
                            $this->imports->run($id, $this->syncChunks);
                        }

                        return $this->next($dialog, $id);
                    }
                    $form->handleRequest($request);
                    $step = $do === 'preview' ? 'mapping' : 'preview';
                    if ($form->isSubmitted() && $form->isValid()) {
                        [$mapping, $policy, $version] = $this->confirmValues($form, $import->columns);
                        if ($do === 'preview') {
                            // 0.4.0: the rows checked against the chosen mapping; nothing is stored (modal preview step).
                            $view = $this->imports->previewMapping($id, $mapping);

                            return $this->showResponse($dialog, 'preview', Response::HTTP_OK, $form, $action, $view, null);
                        }
                        $this->imports->confirm($id, $mapping, $policy, $version);
                        $done = $this->imports->run($id, $this->syncChunks);
                        if (!$dialog) {
                            $this->ui->flash($request, 'success', $done ? 'import.done' : 'import.running');
                        }

                        return $this->next($dialog, $id);
                    }
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } catch (ConflictException $e) {
                    if (!$dialog) {
                        $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                        return $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
                    }
                    // In place: the import as it is now, with the conflict above it.
                    $view   = $this->imports->preview($id);
                    $step   = $view->status === ImportStatus::Preview ? 'mapping' : 'result';
                    $alert  = $this->ui->trans('flash.conflict.' . $e->errorCode);
                    $status = Response::HTTP_CONFLICT;
                } catch (InvalidInputException $e) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    if ($do === 'run' || $do === 'cancel') {
                        $errors = $e->errors;
                        $first  = reset($errors);
                        $alert  = $this->ui->trans(is_string($first) ? $first : 'error.csrf', $e->params);
                    } else {
                        $this->ui->errors($form, $e);
                    }
                    if ($dialog && ($do === 'run' || $do === 'cancel')) {
                        $step = 'result';
                    }
                }
            }

            return $this->showResponse($dialog, $step, $status, $form, $action, $view, $alert);
        });
    }

    /**
     * The page of an import, or the modal's step fragment.
     *
     * @param FormInterface<mixed> $form
     * @param FormInterface<mixed> $action
     */
    private function showResponse(bool $dialog, string $step, int $status, FormInterface $form, FormInterface $action, ImportView $import, ?string $alert): Response
    {
        if ($dialog) {
            return $this->step($step, $status, ['form' => $import->status === ImportStatus::Preview ? $form->createView() : null, 'action' => $action->createView(), 'import' => $import, 'alert' => $alert]);
        }
        if ($alert !== null) {
            $form->addError(new FormError($alert));
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
        ], $status, ImportDialog::privateHeaders());
    }

    /**
     * The mapping, the duplicate policy and the expected version of a valid confirm form.
     *
     * @param FormInterface<mixed> $form
     * @param list<string> $columns
     *
     * @return array{0: array<string, string>, 1: DuplicatePolicy, 2: int}
     */
    private function confirmValues(FormInterface $form, array $columns): array
    {
        $values  = $form->getData();
        $values  = is_array($values) ? $values : [];
        $mapping = [];
        foreach ($columns as $i => $column) {
            $mapping[$column] = is_string($values['col_' . $i] ?? null) ? $values['col_' . $i] : '';
        }

        return [$mapping, DuplicatePolicy::tryFrom(is_string($values['policy'] ?? null) ? $values['policy'] : '') ?? DuplicatePolicy::Skip, is_numeric($values['version'] ?? null) ? (int) $values['version'] : -1];
    }

    /**
     * After a write: the page's 303 redirect, or the modal's 204 with the import's address.
     */
    private function next(bool $dialog, string $id): Response
    {
        $url = $this->ui->url('nowo_crm_kit_import_show', ['id' => $id]);

        return $dialog ? ImportDialog::next($url) : $this->ui->redirect('nowo_crm_kit_import_show', ['id' => $id]);
    }

    /**
     * A step fragment of the import modal (`import/dialog.html.twig`).
     *
     * @param array<string, mixed> $context
     */
    private function step(string $step, int $status, array $context): Response
    {
        return $this->ui->render('@NowoCrmKit/import/dialog.html.twig', ['step' => $step] + $context + ['form' => null, 'action' => null, 'import' => null, 'alert' => null, 'subject' => null], $status, ImportDialog::fragmentHeaders());
    }
}
