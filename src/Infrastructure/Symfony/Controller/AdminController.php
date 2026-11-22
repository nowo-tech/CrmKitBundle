<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FieldDefinitionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\SettingsType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_combine;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_split;
use function trim;

/**
 * Administration (`manage`, contract section 11.1): tags, custom fields and the per-tenant settings. Stateless.
 */
final readonly class AdminController
{
    /** Id of the "New tag" dialog (0.6.1, `ui.admin_create: dialog`). */
    public const TAG_DIALOG = 'crm-tag-dialog';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private CrmReaderInterface $reader,
        private TagServiceInterface $tags,
        private FieldServiceInterface $fields,
        private CrmSettingsInterface $settings,
        private CrmAccess $access,
    ) {
    }

    /**
     * Tags: the list and the create form.
     */
    #[AsWriteUnit]
    public function tags(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context(CrmPermission::Manage);
            $form = $this->ui->form(TagType::class, ['colour' => 'neutral'], ['colours' => $this->colours()], 'crm_tag');
            $form->handleRequest($request);
            $status = Response::HTTP_OK;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    try {
                        $this->tags->create($this->text($form, 'name'), $this->text($form, 'colour'));
                        $this->ui->flash($request, 'success', 'tag.created');

                        return $this->ui->redirect('nowo_crm_kit_tags');
                    } catch (InvalidInputException $e) {
                        $this->ui->errors($form, $e);
                    }
                }
            }
            $rows   = array_map(static fn (TagView $t): array => ['id' => $t->id, 'name' => $t->name, 'colour' => $t->colour], $this->reader->tags());
            $create = null;
            if ($this->ui->options->createDialog()) {
                // 0.6.1: "New tag" is a header action opening the dialog; ?new=1 (its href) is the no-JS fallback.
                $create = $this->create($request, $status, [['form' => $form, 'id' => self::TAG_DIALOG, 'title' => 'tag.add', 'submit' => 'tag.save', 'action' => $this->ui->url('nowo_crm_kit_tags'), 'testid' => 'crm-tag-form', 'open' => true]]);
            }

            return $this->ui->render('@NowoCrmKit/admin/tags.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('tags.title'), UiSupport::label('tags.intro'), $create === null ? [] : [$this->createAction('tag.add', $this->ui->url('nowo_crm_kit_tags', ['new' => 1]), self::TAG_DIALOG, 'crm-tag-new', 'primary')], tabs: $this->ui->tabs('tags')),
                    [new Column('name', UiSupport::label('tag.form.name'), required: true), new Column('colour', UiSupport::label('tag.form.colour'))],
                    $rows,
                    rowActions: fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::edit($this->ui->url('nowo_crm_kit_tag_edit', ['id' => $row['id']]), UiSupport::label('tag.edit'))] : [],
                    // 0.6.1 (UX-005): one sentence, the UiKit `empty` message (no title, so no second "No items found.").
                    empty: new EmptyState(null, UiSupport::label('tags.empty')),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-tags'],
                    responsive: 'cards',
                ),
                'form'       => $create === null ? $form->createView() : $create['dialogs'][0]['form'],
                'crm_create' => $create,
            ], $status);
        });
    }

    /**
     * One tag: rename, recolour, delete.
     */
    #[AsWriteUnit]
    public function tag(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $tag = null;
            foreach ($this->reader->tags() as $candidate) {
                $tag = $candidate->id === $id ? $candidate : $tag;
            }
            if (!$tag instanceof TagView) {
                throw new NotAvailableException();
            }
            $this->access->context(CrmPermission::Manage);
            $form   = $this->ui->form(TagType::class, ['name' => $tag->name, 'colour' => $tag->colour, 'version' => (string) $tag->version], ['colours' => $this->colours(), 'versioned' => true], 'crm_tag');
            $delete = $this->ui->form(ActionType::class, null, [], 'crm_action');
            $status = Response::HTTP_OK;
            try {
                $delete->handleRequest($request);
                if ($delete->isSubmitted() && $delete->isValid()) {
                    $this->tags->delete($id);
                    $this->ui->flash($request, 'success', 'tag.deleted');

                    return $this->ui->redirect('nowo_crm_kit_tags');
                }
                $form->handleRequest($request);
                if ($form->isSubmitted()) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    if ($form->isValid()) {
                        $this->tags->change($id, $this->text($form, 'name'), $this->text($form, 'colour'), $this->int($form, 'version'));
                        $this->ui->flash($request, 'success', 'tag.saved');

                        return $this->ui->redirect('nowo_crm_kit_tags');
                    }
                }
            } catch (InvalidInputException $e) {
                $this->ui->errors($form, $e);
            } catch (ConflictException $e) {
                $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                return $this->ui->redirect('nowo_crm_kit_tag_edit', ['id' => $id]);
            }

            return $this->ui->render('@NowoCrmKit/admin/tag.html.twig', [
                'page'   => new FormPage(new PageHeader(UiSupport::label('tag.edit_title', ['%name%' => $tag->name]), null, [], tabs: $this->ui->tabs('tags')), $form->createView(), UiSupport::label('form.save'), $this->ui->url('nowo_crm_kit_tags'), UiSupport::label('form.cancel'), bundle: UiSupport::DOMAIN, layout: $this->ui->layout()),
                'delete' => $delete->createView(),
            ], $status);
        });
    }

    /**
     * Custom fields of both types and the define form.
     */
    #[AsWriteUnit]
    public function fields(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context(CrmPermission::Manage);
            $subject = SubjectType::tryFrom((string) $request->query->get('for', 'company')) ?? SubjectType::Company;
            $form    = $this->fieldForm();
            $form->handleRequest($request);
            $status = Response::HTTP_OK;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    try {
                        $this->fields->define($subject, $this->text($form, 'key'), $this->text($form, 'label'), FieldType::from($this->text($form, 'type')), $this->lines($this->text($form, 'choices')), $this->bool($form, 'required'), $this->int($form, 'position'));
                        $this->ui->flash($request, 'success', 'field.defined');

                        return $this->ui->redirect('nowo_crm_kit_fields', ['for' => $subject->value]);
                    } catch (InvalidInputException $e) {
                        $this->ui->errors($form, $e);
                    }
                }
            }
            $rows = [];
            foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
                $rows = array_merge($rows, array_map(fn (FieldView $f): array => ['id' => $f->id, 'subject' => $this->ui->trans('type.' . $f->subjectType->value), 'key' => $f->key, 'label' => $f->label, 'type' => $this->ui->trans('field_type.' . $f->type->value), 'state' => $this->ui->trans($f->archived ? 'field.archived' : 'field.active')], $this->reader->fields($type, true)));
            }

            $create  = null;
            $actions = [];
            $view    = null;
            if ($this->ui->options->createDialog()) {
                // 0.6.1: one header action and one dialog per subject; the submitted (or ?new=1) one opens.
                $dialogs = [];
                foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
                    $id        = 'crm-field-dialog-' . $type->value;
                    $dialogs[] = ['form' => $type === $subject ? $form : $this->fieldForm(), 'id' => $id, 'title' => 'field.add.' . $type->value, 'submit' => 'field.save', 'action' => $this->ui->url('nowo_crm_kit_fields', ['for' => $type->value]), 'testid' => 'crm-field-form-' . $type->value, 'open' => $type === $subject];
                    $actions[] = $this->createAction('field.add.' . $type->value, $this->ui->url('nowo_crm_kit_fields', ['for' => $type->value, 'new' => 1]), $id, 'crm-field-new-' . $type->value, $type === SubjectType::Company ? 'primary' : null);
                }
                $create = $this->create($request, $status, $dialogs);
                $view   = $create['dialogs'][$subject === SubjectType::Company ? 0 : 1]['form'] ?? null;
            }

            return $this->ui->render('@NowoCrmKit/admin/fields.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('fields.title'), UiSupport::label('fields.intro'), $actions, tabs: $this->ui->tabs('fields')),
                    [new Column('subject', UiSupport::label('fields.col.subject')), new Column('label', UiSupport::label('field.form.label'), required: true), new Column('key', UiSupport::label('field.form.key'), priority: 2), new Column('type', UiSupport::label('field.form.type'), priority: 2), new Column('state', UiSupport::label('fields.col.state'), priority: 3)],
                    $rows,
                    rowActions: fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::edit($this->ui->url('nowo_crm_kit_field_edit', ['id' => $row['id']]), UiSupport::label('field.edit'))] : [],
                    // 0.6.1 (UX-005): one sentence, the UiKit `empty` message.
                    empty: new EmptyState(null, UiSupport::label('fields.empty')),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-fields'],
                    responsive: 'cards',
                ),
                'form'       => $view ?? $form->createView(),
                'subject'    => $subject->value,
                'crm_create' => $create,
            ], $status);
        });
    }

    /**
     * One field: change, archive or bring back.
     */
    #[AsWriteUnit]
    public function field(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $field = null;
            foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
                foreach ($this->reader->fields($type, true) as $candidate) {
                    $field = $candidate->id === $id ? $candidate : $field;
                }
            }
            if (!$field instanceof FieldView) {
                throw new NotAvailableException();
            }
            $this->access->context(CrmPermission::Manage);
            $form    = $this->ui->form(FieldDefinitionType::class, ['key' => $field->key, 'label' => $field->label, 'type' => $field->type->value, 'choices' => implode("\n", $field->choices), 'required' => $field->required, 'position' => $field->position, 'version' => (string) $field->version], ['types' => $this->types(), 'versioned' => true], 'crm_field');
            $archive = $this->ui->form(ActionType::class, ['version' => (string) $field->version], ['versioned' => true], 'crm_action');
            $status  = Response::HTTP_OK;
            try {
                $archive->handleRequest($request);
                if ($archive->isSubmitted() && $archive->isValid()) {
                    // The version the page showed: a field changed meanwhile is a conflict, never archived blind.
                    $this->fields->archive($id, !$field->archived, $this->int($archive, 'version'));
                    $this->ui->flash($request, 'success', $field->archived ? 'field.restored' : 'field.archived_flash');

                    return $this->ui->redirect('nowo_crm_kit_fields');
                }
                $form->handleRequest($request);
                if ($form->isSubmitted()) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    if ($form->isValid()) {
                        $this->fields->change($id, $this->text($form, 'label'), $this->lines($this->text($form, 'choices')), $this->bool($form, 'required'), $this->int($form, 'position'), $this->int($form, 'version'));
                        $this->ui->flash($request, 'success', 'field.saved');

                        return $this->ui->redirect('nowo_crm_kit_fields');
                    }
                }
            } catch (InvalidInputException $e) {
                $this->ui->errors($form, $e);
            } catch (ConflictException $e) {
                $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                return $this->ui->redirect('nowo_crm_kit_field_edit', ['id' => $id]);
            }

            return $this->ui->render('@NowoCrmKit/admin/field.html.twig', [
                'page'     => new FormPage(new PageHeader(UiSupport::label('field.edit_title', ['%name%' => $field->label]), null, [], tabs: $this->ui->tabs('fields')), $form->createView(), UiSupport::label('form.save'), $this->ui->url('nowo_crm_kit_fields'), UiSupport::label('form.cancel'), bundle: UiSupport::DOMAIN, layout: $this->ui->layout()),
                'archive'  => $archive->createView(),
                'archived' => $field->archived,
            ], $status);
        });
    }

    /**
     * Per-tenant settings.
     */
    #[AsWriteUnit]
    public function settings(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $current = $this->settings->get();
            $form    = $this->ui->form(SettingsType::class, ['trashDays' => $current['trash_days'], 'timelineYears' => $current['timeline_years'], 'importKeepDays' => $current['import_keep_days'], 'mergeKeepDays' => $current['merge_keep_days'], 'version' => (string) $current['version']], [], 'crm_settings');
            $form->handleRequest($request);
            $status = Response::HTTP_OK;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    try {
                        $this->settings->update($this->int($form, 'trashDays'), $this->int($form, 'timelineYears'), $this->int($form, 'importKeepDays'), $this->int($form, 'mergeKeepDays'), $this->int($form, 'version'));
                        $this->ui->flash($request, 'success', 'settings.saved');

                        return $this->ui->redirect('nowo_crm_kit_settings');
                    } catch (InvalidInputException $e) {
                        $this->ui->errors($form, $e);
                    } catch (ConflictException $e) {
                        $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                        return $this->ui->redirect('nowo_crm_kit_settings');
                    }
                }
            }

            return $this->ui->render('@NowoCrmKit/admin/settings.html.twig', [
                'page'              => new FormPage(new PageHeader(UiSupport::label('settings.title'), UiSupport::label('settings.intro'), [], tabs: $this->ui->tabs('settings')), $form->createView(), UiSupport::label('form.save'), bundle: UiSupport::DOMAIN, layout: $this->ui->layout(), attributes: ['data-testid' => 'crm-settings']),
                'crm_settings_full' => $this->ui->options->settingsFull(),
            ], $status);
        });
    }

    /**
     * The create dialogs of an admin list (0.6.1, `ui.admin_create: dialog`): each dialog's form view (ids
     * `<dialog id>-…` so two dialogs never share an id), the dialog that opens on load (an invalid submission, 422, or
     * the `?new=1` deep link, which is also the no-JS fallback) and that form once more for the `<noscript>` fallback
     * card (ids `<dialog id>-page-…`).
     *
     * @param list<array{form: FormInterface<mixed>, id: string, title: string, submit: string, action: string, testid: string, open: bool}> $dialogs
     *
     * @return array{dialogs: list<array{form: FormView, id: string, title: string, submit: string, action: string, testid: string}>, open: string|null, clear: false|string, fallback: array{form: FormView, title: string, submit: string, action: string, testid: string}|null}
     */
    private function create(Request $request, int $status, array $dialogs): array
    {
        $invalid  = $status === Response::HTTP_UNPROCESSABLE_ENTITY;
        $deepLink = $request->isMethod('GET') && $request->query->getBoolean('new');
        $views    = [];
        $open     = null;
        $fallback = null;
        foreach ($dialogs as $dialog) {
            $views[] = ['form' => $this->ui->view($dialog['form'], $dialog['id']), 'id' => $dialog['id'], 'title' => $dialog['title'], 'submit' => $dialog['submit'], 'action' => $dialog['action'], 'testid' => $dialog['testid']];
            if ($dialog['open'] && ($invalid || $deepLink)) {
                $open     = $dialog['id'];
                $fallback = ['form' => $this->ui->view($dialog['form'], $dialog['id'] . '-page'), 'title' => $dialog['title'], 'submit' => $dialog['submit'], 'action' => $dialog['action'], 'testid' => $dialog['testid'] . '-page'];
            }
        }

        return ['dialogs' => $views, 'open' => $open, 'clear' => $invalid ? false : 'new', 'fallback' => $fallback];
    }

    /**
     * A header action that opens a create dialog (UiKit `js/nowo-ui-confirm.js`: data-nowo-confirm-open / -target);
     * its href, the page with `?new=1`, is the no-JS fallback.
     */
    private function createAction(string $label, string $href, string $dialog, string $testid, ?string $variant): Action
    {
        return new Action('create', UiSupport::label($label), $href, variant: $variant, attributes: ['data-nowo-confirm-open' => true, 'data-nowo-confirm-target' => $dialog, 'aria-haspopup' => 'dialog', 'aria-controls' => $dialog, 'data-testid' => $testid]);
    }

    /**
     * The define form of a custom field.
     *
     * @return FormInterface<mixed>
     */
    private function fieldForm(): FormInterface
    {
        return $this->ui->form(FieldDefinitionType::class, ['type' => 'text', 'position' => 0], ['types' => $this->types()], 'crm_field');
    }

    /**
     * Tag colour choices.
     *
     * @return array<string, string>
     */
    private function colours(): array
    {
        return array_combine(array_map(static fn (string $c): string => 'colour.' . $c, Tag::COLOURS), Tag::COLOURS);
    }

    /**
     * Field type choices.
     *
     * @return array<string, string>
     */
    private function types(): array
    {
        $types = [];
        foreach (FieldType::cases() as $case) {
            $types['field_type.' . $case->value] = $case->value;
        }

        return $types;
    }

    /**
     * A text value of a form ('' when absent).
     *
     * @param FormInterface<mixed> $form
     */
    private function text(FormInterface $form, string $name): string
    {
        $value = $form->has($name) ? $form->get($name)->getData() : null;

        return is_string($value) ? $value : '';
    }

    /**
     * An integer value of a form (-1 when absent).
     *
     * @param FormInterface<mixed> $form
     */
    private function int(FormInterface $form, string $name): int
    {
        $value = $form->has($name) ? $form->get($name)->getData() : null;

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * A checkbox value of a form.
     *
     * @param FormInterface<mixed> $form
     */
    private function bool(FormInterface $form, string $name): bool
    {
        return $form->has($name) && $form->get($name)->getData() === true;
    }

    /**
     * The non-empty lines of a text.
     *
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(preg_split('/\R/', $text) ?: [], static fn (string $l): bool => trim($l) !== ''));
    }
}
