<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\MergeField;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\MergePickType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\MergeType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_map;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * Lists, 360° pages, forms, trash / restore / purge, merge and CSV export of companies and contacts (contract
 * sections 9 and 11.1). The type comes from the URL segment (`companies`, `contacts`). Stateless.
 */
final readonly class RecordController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private CrmReaderInterface $reader,
        private RecordServiceInterface $records,
        private MergeServiceInterface $merges,
        private CrmExporterInterface $exporter,
        private RecordForms $forms,
        private RecordPage $page,
        private ?ImportServiceInterface $imports = null,
    ) {
    }

    /**
     * `/` ⇒ the companies.
     */
    public function home(): RedirectResponse
    {
        return $this->ui->redirect('nowo_crm_kit_list', ['type' => 'companies']);
    }

    /**
     * A list with filters, sort and pages.
     */
    public function list(Request $request, string $type): Response
    {
        return $this->ui->guard(function () use ($request, $type): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = self::type($type);
            $tags    = [];
            foreach ($this->reader->tags() as $tag) {
                $tags[$tag->name] = $tag->id;
            }
            $filters = $this->ui->form(FilterType::class, null, ['tags' => $tags, 'owners' => $this->forms->owners(), 'roles' => $this->forms->roles(), 'purposes' => $subject === SubjectType::Contact ? $this->forms->affiliationPurposes() : []], '');
            $filters->handleRequest($request);
            $query  = $this->query($request, $subject, $tags);
            $result = $this->reader->list($query);
            $token  = $this->ui->actionToken();
            $rows   = [];
            foreach ($result->rows as $view) {
                $rows[] = $this->row($view);
            }
            $canEdit   = $this->ui->can(CrmPermission::Edit);
            $canDelete = $this->ui->can(CrmPermission::Delete);
            $actions   = [];
            if ($this->ui->can(CrmPermission::Create)) {
                $actions[] = Action::create($this->ui->url('nowo_crm_kit_new', ['type' => $type]), UiSupport::label('record.new.' . $subject->value));
            }
            if ($this->ui->can(CrmPermission::Export)) {
                $actions[] = new Action('export', UiSupport::label('list.export'), $this->ui->url('nowo_crm_kit_export', ['type' => $type] + $request->query->all()), icon: 'download');
            }
            // 0.4.0: "Import" opens the import modal (js/nowo-crm-import.js); its href, the import page, is the no-JS
            // fallback. Only for someone who may import, like the page itself.
            $importDialog = null;
            if ($this->ui->can(CrmPermission::Import)) {
                $importDialog = ['url' => $this->ui->url('nowo_crm_kit_import', ['subject' => $subject->value])];
                $actions[]    = new Action('import', UiSupport::label('list.import'), $importDialog['url'], attributes: ['data-crm-import-open' => true, 'aria-haspopup' => 'dialog', 'data-testid' => 'crm-import-open']);
                // Audit XU-M1: the person's unfinished imports stay reachable once the modal is closed.
                $importDialog['unfinished'] = $this->imports?->unfinished() ?? ['total' => 0, 'imports' => []];
            }
            $columns = $subject === SubjectType::Company
                ? [new Column('label', UiSupport::label('field.name'), true, required: true, sortKey: 'name'), new Column('city', UiSupport::label('field.city'), priority: 2), new Column('email', UiSupport::label('field.email'), priority: 3), new Column('owner', UiSupport::label('field.owner'), priority: 3), new Column('roles', UiSupport::label('field.roles'), priority: 2), new Column('tags', UiSupport::label('field.tags'), priority: 2), new Column('updated', UiSupport::label('list.col.updated'), true, priority: 3, sortKey: 'updated')]
                : [new Column('label', UiSupport::label('field.contact_name'), true, required: true, sortKey: 'name'), new Column('email', UiSupport::label('field.email')), new Column('phone', UiSupport::label('field.phone'), priority: 2), new Column('owner', UiSupport::label('field.owner'), priority: 3), new Column('roles', UiSupport::label('field.roles'), priority: 2), new Column('tags', UiSupport::label('field.tags'), priority: 2), new Column('updated', UiSupport::label('list.col.updated'), true, priority: 3, sortKey: 'updated')];

            return $this->ui->render('@NowoCrmKit/record/list.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('list.title.' . $subject->value), null, $actions, tabs: $this->ui->tabs($type), actionsLayout: $this->ui->options->headerSplit() ? 'split' : 'toolbar'),
                    $columns,
                    $rows,
                    $filters->createView(),
                    function (mixed $row) use ($type, $canEdit, $canDelete, $token, $query): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
                        $id      = is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : '';
                        $menu    = $this->ui->options->rowMenu() ? ['data-crm-menu' => true] : [];
                        $actions = [Action::view($this->ui->url('nowo_crm_kit_show', ['type' => $type, 'id' => $id]), UiSupport::label('record.open'))];
                        if ($canEdit && $query->status === RecordStatus::Active) {
                            $actions[] = Action::edit($this->ui->url('nowo_crm_kit_edit', ['type' => $type, 'id' => $id]), UiSupport::label('record.edit'));
                        }
                        // 0.6.0 (UX-003, UX-026): with ui.row_actions: menu the secondary and destructive actions go into the
                        // row-actions menu, Delete last (the row partial reads the marker).
                        if ($canDelete && $query->status === RecordStatus::Trashed) {
                            $actions[] = new Action('restore', UiSupport::label('record.restore'), $this->ui->url('nowo_crm_kit_restore', ['type' => $type, 'id' => $id]), method: 'POST', csrfToken: $token, attributes: $menu, csrfField: 'crm_action[_token]');
                        }
                        if ($canDelete && $query->status === RecordStatus::Active) {
                            $actions[] = new Action('delete', UiSupport::label('record.trash'), $this->ui->url('nowo_crm_kit_trash', ['type' => $type, 'id' => $id]), method: 'POST', csrfToken: $token, confirm: new Confirm(UiSupport::label('record.trash_confirm'), null, UiSupport::label('record.trash')), attributes: $menu, csrfField: 'crm_action[_token]');
                        }

                        return $actions;
                    },
                    Pagination::fromTotal($result->total, $query->page, $query->perPage, route: 'nowo_crm_kit_list', query: $request->query->all()),
                    new EmptyState(UiSupport::label('list.empty.' . $subject->value), UiSupport::label('list.empty_hint'), $this->ui->can(CrmPermission::Create) ? Action::create($this->ui->url('nowo_crm_kit_new', ['type' => $type]), UiSupport::label('record.new.' . $subject->value)) : null),
                    $this->ui->url('nowo_crm_kit_list', ['type' => $type]),
                    $query->sort === 'name' ? 'label' : $query->sort,
                    $query->direction,
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-list-' . $subject->value],
                    filterFields: [new FilterField('q', UiSupport::label('list.filter.q_placeholder'), 'lg', attributes: ['aria-label' => $this->ui->trans('list.filter.q')])],
                    responsive: 'cards',
                    filtersFold: $this->ui->options->filtersFold ? true : null,
                ),
                'crm_import_dialog' => $importDialog,
            ]);
        });
    }

    /**
     * A new record.
     */
    #[AsWriteUnit]
    public function create(Request $request, string $type): Response
    {
        return $this->ui->guard(function () use ($request, $type): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = self::type($type);
            $this->ui->require(CrmPermission::Create);
            $form = $this->forms->form($subject, null);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $id = $this->records->create($this->forms->input($subject, $form));
                    $this->ui->flash($request, 'success', 'record.created');

                    return $this->ui->toRecord($subject, $id);
                } catch (InvalidInputException $e) {
                    $this->ui->errors($form, $e);
                } catch (ConflictException $e) {
                    $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                    return $this->formPage($subject, $form, null, Response::HTTP_CONFLICT);
                }
            }

            return $this->formPage($subject, $form, null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * The 360° page (a merged id redirects to the survivor).
     */
    public function show(Request $request, string $type, string $id): Response
    {
        return $this->ui->guard(function () use ($type, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = self::type($type);
            $view    = $this->reader->record($subject, $id);
            if ($view->status === RecordStatus::Merged && $view->mergedIntoId !== null) {
                return $this->ui->toRecord($subject, $view->mergedIntoId);
            }

            return $this->page->render($view);
        });
    }

    /**
     * Edits a record (stale version ⇒ the form again with the current values, 409).
     */
    #[AsWriteUnit]
    public function edit(Request $request, string $type, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $type, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = self::type($type);
            $view    = $this->reader->record($subject, $id);
            if ($view->status !== RecordStatus::Active) {
                return $this->ui->toRecord($subject, $view->mergedIntoId ?? $id);
            }
            $this->ui->requireOn(CrmPermission::Edit, $view);
            $form = $this->forms->form($subject, $view);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data    = $form->getData();
                $version = is_array($data) && is_numeric($data['version'] ?? null) ? (int) $data['version'] : -1;
                try {
                    $this->records->update($id, $this->forms->input($subject, $form), $version);
                    $this->ui->flash($request, 'success', 'record.saved');

                    return $this->ui->toRecord($subject, $id);
                } catch (InvalidInputException $e) {
                    $this->ui->errors($form, $e);
                } catch (ConflictException $e) {
                    $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);
                    $fresh = $this->reader->record($subject, $id);

                    return $this->formPage($subject, $this->forms->form($subject, $fresh), $fresh, Response::HTTP_CONFLICT);
                }
            }

            return $this->formPage($subject, $form, $view, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * Moves a record to the trash.
     */
    #[AsWriteUnit]
    public function trash(Request $request, string $type, string $id): Response
    {
        $subject = self::type($type);

        return $this->ui->act($request, function () use ($request, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $version = $request->request->get('version');
            $this->records->trash($subject, $id, is_numeric($version) ? (int) $version : $this->reader->record($subject, $id)->version);
            $this->ui->flash($request, 'success', 'record.trashed');

            return $this->ui->redirect('nowo_crm_kit_list', ['type' => $subject->segment()]);
        }, $this->ui->toRecord($subject, $id));
    }

    /**
     * Brings a record back.
     */
    #[AsWriteUnit]
    public function restore(Request $request, string $type, string $id): Response
    {
        $subject = self::type($type);

        return $this->ui->act($request, function () use ($request, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $this->records->restore($subject, $id);
            $this->ui->flash($request, 'success', 'record.restored');

            return $this->ui->toRecord($subject, $id);
        }, $this->ui->toRecord($subject, $id));
    }

    /**
     * Deletes a trashed record for good.
     */
    #[AsWriteUnit]
    public function purge(Request $request, string $type, string $id): Response
    {
        $subject = self::type($type);

        return $this->ui->act($request, function () use ($request, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $this->records->purge($subject, $id);
            $this->ui->flash($request, 'success', 'record.purged');

            return $this->ui->redirect('nowo_crm_kit_list', ['type' => $subject->segment(), 'status' => 'trashed']);
        }, $this->ui->toRecord($subject, $id));
    }

    /**
     * Pick the other record, preview, confirm.
     */
    #[AsWriteUnit]
    public function merge(Request $request, string $type, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $type, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = self::type($type);
            $other   = $request->query->get('other');
            if (!is_string($other) || !RecordId::isValid($other)) {
                return $this->mergePick($request, $subject, $id);
            }
            try {
                $preview = $this->merges->preview($subject, $id, $other);
            } catch (ConflictException $e) {
                $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                return $this->ui->toRecord($subject, $id);
            }
            $labels = array_map(fn (MergeField $f): string => $this->fieldLabel($subject, $f->field) . ': ' . ($f->other ?? '—'), $preview->fields);
            $form   = $this->ui->form(MergeType::class, ['other' => $other, 'survivorVersion' => (string) $preview->survivor->version, 'otherVersion' => (string) $preview->other->version] + $this->defaults($preview->fields), ['fields' => $labels], 'crm_merge');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $data = is_array($data) ? $data : [];
                $take = [];
                foreach ($preview->fields as $i => $field) {
                    if (($data['take_' . $i] ?? false) === true) {
                        $take[] = $field->field;
                    }
                }
                try {
                    $this->merges->merge($subject, $id, $other, $take, is_numeric($data['survivorVersion'] ?? null) ? (int) $data['survivorVersion'] : -1, is_numeric($data['otherVersion'] ?? null) ? (int) $data['otherVersion'] : -1);
                    $this->ui->flash($request, 'success', 'merge.done');

                    return $this->ui->toRecord($subject, $id);
                } catch (ConflictException $e) {
                    $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                    return $this->ui->toRecord($subject, $id);
                }
            }

            return $this->ui->render('@NowoCrmKit/record/merge.html.twig', [
                'page' => new FormPage(
                    new PageHeader(UiSupport::label('merge.title', ['%name%' => $preview->survivor->label]), UiSupport::label('merge.intro')),
                    $form->createView(),
                    UiSupport::label('merge.confirm'),
                    $this->ui->url('nowo_crm_kit_show', ['type' => $type, 'id' => $id]),
                    UiSupport::label('form.cancel'),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-merge'],
                ),
                'preview' => $preview,
                'labels'  => array_map(fn (MergeField $f): string => $this->fieldLabel($subject, $f->field), $preview->fields),
            ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * CSV of the list's filters (`manage`).
     */
    public function export(Request $request, string $type): Response
    {
        return $this->ui->guard(function () use ($request, $type): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = self::type($type);
            $lines   = $this->exporter->csv($this->query($request, $subject, null, 100));
            // The first line is produced now, so a refusal is a 403 / 404 before the stream starts.
            $first = null;
            foreach ($lines as $line) {
                $first = $line;
                break;
            }
            $response = new StreamedResponse(static function () use ($lines, $first): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                echo $first;
                $skip = true;
                foreach ($lines as $line) {
                    if ($skip) {
                        $skip = false;

                        continue;
                    }
                    echo $line;
                }
            });
            $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="crm-' . $subject->segment() . '.csv"');
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        });
    }

    private function mergePick(Request $request, SubjectType $type, string $id): Response
    {
        $view = $this->reader->record($type, $id);
        if ($view->status !== RecordStatus::Active) {
            return $this->ui->toRecord($type, $view->mergedIntoId ?? $id);
        }
        $this->ui->requireOn(CrmPermission::Edit, $view);
        $form = $this->ui->form(MergePickType::class, null, [], 'crm_merge_pick');
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data  = $form->getData();
            $other = is_array($data) && is_string($data['other'] ?? null) ? $data['other'] : '';
            if (RecordId::isValid($other)) {
                return new RedirectResponse($this->ui->url('nowo_crm_kit_merge', ['type' => $type->segment(), 'id' => $id, 'other' => $other]), Response::HTTP_SEE_OTHER);
            }
            $this->ui->errors($form, InvalidInputException::field('other', 'error.invalid'));
        }

        return $this->ui->render('@NowoCrmKit/record/merge_pick.html.twig', [
            'page' => new FormPage(
                new PageHeader(UiSupport::label('merge.title', ['%name%' => $view->label]), UiSupport::label('merge.pick_intro')),
                $form->createView(),
                UiSupport::label('merge.preview'),
                $this->ui->url('nowo_crm_kit_show', ['type' => $type->segment(), 'id' => $id]),
                UiSupport::label('form.cancel'),
                bundle: UiSupport::DOMAIN,
                layout: $this->ui->layout(),
            ),
            'duplicates' => array_map(fn (RecordView $d): array => ['label' => $d->label, 'href' => $this->ui->url('nowo_crm_kit_merge', ['type' => $type->segment(), 'id' => $id, 'other' => $d->id])], $this->reader->duplicates($type, $id)),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * The label of a merge field.
     */
    private function fieldLabel(SubjectType $type, string $field): string
    {
        if (!str_starts_with($field, 'custom.')) {
            return $this->ui->trans('field.' . $field);
        }
        $key = substr($field, 7);
        foreach ($this->forms->fields($type) as $definition) {
            if ($definition->key === $key) {
                return $definition->label;
            }
        }

        return $key;
    }

    /**
     * The merge form's default choices.
     *
     * @param list<MergeField> $fields
     *
     * @return array<string, bool>
     */
    private function defaults(array $fields): array
    {
        $data = [];
        foreach ($fields as $i => $field) {
            $data['take_' . $i] = $field->takeOther;
        }

        return $data;
    }

    /**
     * The record form page.
     *
     * @param FormInterface<mixed> $form
     */
    private function formPage(SubjectType $type, FormInterface $form, ?RecordView $view, int $status): Response
    {
        $title = $view instanceof RecordView ? UiSupport::label('record.edit_title', ['%name%' => $view->label]) : UiSupport::label('record.new.' . $type->value);
        $back  = $view instanceof RecordView ? $this->ui->url('nowo_crm_kit_show', ['type' => $type->segment(), 'id' => $view->id]) : $this->ui->url('nowo_crm_kit_list', ['type' => $type->segment()]);

        return $this->ui->render('@NowoCrmKit/record/form.html.twig', [
            'page' => new FormPage(
                new PageHeader($title, null, [], tabs: $this->ui->tabs($type->segment())),
                $form->createView(),
                UiSupport::label('form.save'),
                $back,
                UiSupport::label('form.cancel'),
                bundle: UiSupport::DOMAIN,
                layout: $this->ui->layout(),
                attributes: ['data-testid' => 'crm-record-form'],
            ),
        ], $status);
    }

    /**
     * The list query of the request.
     *
     * @param array<string, string>|null $tags tag name => id (filter values are checked against them)
     */
    private function query(Request $request, SubjectType $type, ?array $tags, ?int $perPage = null): ListQuery
    {
        $q      = $request->query->all();
        $text   = is_string($q['q'] ?? null) ? $q['q'] : null;
        $tag    = is_string($q['tag'] ?? null) && RecordId::isValid($q['tag']) && ($tags === null || in_array($q['tag'], $tags, true)) ? $q['tag'] : null;
        $owner  = is_string($q['owner'] ?? null) && $q['owner'] !== '' && ExactId::isValid($q['owner']) ? $q['owner'] : null;
        $status = ($q['status'] ?? null) === 'trashed' ? RecordStatus::Trashed : RecordStatus::Active;
        $sort   = is_string($q['sort'] ?? null) ? ($q['sort'] === 'label' ? 'name' : $q['sort']) : 'name';
        // Only configured keys filter (byte for byte); anything else is ignored like an unknown tag.
        $role    = is_string($q['role'] ?? null) && in_array($q['role'], $this->forms->roles(), true) ? $q['role'] : null;
        $purpose = $type === SubjectType::Contact && is_string($q['purpose'] ?? null) && in_array($q['purpose'], $this->forms->affiliationPurposes(), true) ? $q['purpose'] : null;

        return new ListQuery(
            $type,
            $text,
            $tag,
            $owner,
            $status,
            $sort,
            is_string($q['direction'] ?? null) ? $q['direction'] : 'asc',
            is_numeric($q['page'] ?? null) ? (int) $q['page'] : 1,
            $perPage ?? (is_numeric($q['per_page'] ?? null) ? (int) $q['per_page'] : $this->ui->pageSize),
            role: $role,
            purpose: $purpose,
            primaryContactOnly: $type === SubjectType::Contact && ($q['primary_contact'] ?? null) === '1',
        );
    }

    /**
     * A list row.
     *
     * @return array<string, int|string|null>
     */
    private function row(RecordView $view): array
    {
        return [
            'id'      => $view->id,
            'version' => $view->version,
            'label'   => $view->label,
            'city'    => $view->values['city'] ?? null,
            'email'   => $view->values['email'] ?? null,
            'phone'   => $view->values['phone'] ?? $view->values['mobile'] ?? null,
            'owner'   => $view->ownerName ?? $view->ownerId,
            'roles'   => implode(', ', array_map(fn (string $r): string => $this->ui->trans('role.' . $r), $view->roles)),
            'tags'    => implode(', ', array_map(static fn (TagView $t): string => $t->name, $view->tags)),
            'updated' => $view->updatedAt->format('Y-m-d'),
        ];
    }

    /**
     * Checks the CSRF token of an action button.
     */
    private function csrf(Request $request): void
    {
        $form = $this->ui->form(ActionType::class, null, [], 'crm_action');
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw InvalidInputException::field('_', 'error.csrf');
        }
    }

    /**
     * The type of a URL segment (the route requirement allows only the two).
     */
    public static function type(string $segment): SubjectType
    {
        return SubjectType::fromSegment($segment) ?? throw new NotFoundHttpException();
    }
}
