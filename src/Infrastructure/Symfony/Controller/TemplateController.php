<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\RateLimitedException;
use Nowo\CrmKitBundle\Domain\Read\EmailTemplateView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplatesInterface;
use Nowo\CrmKitBundle\Domain\Service\MailComposerInterface;
use Nowo\CrmKitBundle\Domain\Service\MailDraft;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\EmailTemplateType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\WriteEmailType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function rtrim;

/**
 * The e-mail templates (0.13.0, `ui.enabled` and `templates.enabled`): the list of the person's and the organisation's
 * templates and snippets, their create / edit forms (each its own page, loaded on demand into the list's remote
 * dialog), delete, and *Write e-mail* on a contact's 360° page (its own page, loaded into the activity dialog): the
 * template and snippet inserts are rendered by the server for the contact (MailKit's closed placeholders, never Twig),
 * Send goes through {@see MailComposerInterface}. Every action passes the CRM's gate (404 outside the tenant or the
 * feature, 403 without the permission) and carries its CSRF token. Stateless.
 *
 * @internal
 */
final readonly class TemplateController
{
    /** Id of the remote dialog of the templates list. */
    public const DIALOG = 'crm-template-dialog';

    /**
     * @param list<string> $purposes the configured consent purposes (`gdpr.purposes`)
     */
    public function __construct(
        private UiSupport $ui,
        private EmailTemplatesInterface $templates,
        private MailComposerInterface $composer,
        private CrmReaderInterface $reader,
        private PipelineDialogs $dialogs,
        private PipelineDialogs $recordDialogs,
        private array $purposes,
        private ?DealReader $deals = null,
    ) {
    }

    /**
     * The person's and the organisation's templates and snippets.
     */
    public function list(): Response
    {
        return $this->ui->guard(function (): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Edit);
            $rows  = [];
            $token = $this->ui->actionToken();
            foreach ($this->templates->list() as $template) {
                $rows[] = [
                    'id'      => $template->id,
                    'name'    => $template->name,
                    'kind'    => $this->ui->trans('template.kind.' . $template->kind->value),
                    'scope'   => $this->ui->trans($template->shared ? 'template.scope.shared' : 'template.scope.personal'),
                    'locale'  => $template->locale,
                    'subject' => $template->subject,
                    'consent' => $this->consent($template),
                    'row'     => $template,
                ];
            }
            $create = [];
            foreach (EmailTemplateKind::cases() as $kind) {
                $create[] = $this->dialogs->opener('custom', 'template.new_' . $kind->value, 'nowo_crm_kit_template_new', ['kind' => $kind->value], 'crm-template-new-' . $kind->value, $kind === EmailTemplateKind::Template ? 'primary' : 'secondary');
            }

            return $this->ui->render('@NowoCrmKit/templates/list.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('templates.title'), UiSupport::label('templates.intro'), $create, tabs: $this->ui->tabs('templates')),
                    [
                        new Column('name', UiSupport::label('template.form.name'), required: true),
                        new Column('kind', UiSupport::label('template.form.kind')),
                        new Column('scope', UiSupport::label('template.form.scope')),
                        new Column('locale', UiSupport::label('template.form.locale'), priority: 3),
                        new Column('subject', UiSupport::label('template.form.subject'), priority: 2),
                        new Column('consent', UiSupport::label('template.form.purpose')),
                    ],
                    $rows,
                    rowActions: fn (mixed $row): array => is_array($row) && ($row['row'] ?? null) instanceof EmailTemplateView ? $this->rowActions($row['row'], $token) : [],
                    empty: new EmptyState(UiSupport::label('templates.empty'), UiSupport::label('templates.empty_hint')),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-templates'],
                    responsive: 'cards',
                ),
                'crm_dialog' => $this->dialogs->shell('templates.title'),
            ]);
        });
    }

    /**
     * A new template or snippet (its own page; GET shows the form).
     */
    public function create(Request $request, string $kind): Response
    {
        return $this->ui->guard(function () use ($request, $kind): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Edit);
            $type   = EmailTemplateKind::from($kind);
            $form   = $this->form($type, ['locale' => $request->getLocale(), 'shared' => '0', 'purpose' => EmailTemplateType::SERVICE], true, false);
            $status = $this->submit($request, $form, function () use ($request, $form, $type): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $this->templates->create($this->input($type, $form, null));
                $this->ui->flash($request, 'success', 'template.created');

                return $this->dialogs->done($request, 'nowo_crm_kit_templates');
            });
            if ($status instanceof Response) {
                return $status;
            }

            return $this->dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_template_new', ['kind' => $type->value]), 'template.new_' . $type->value, 'template.save', $this->ui->url('nowo_crm_kit_templates'), 'crm-template', $status);
        });
    }

    /**
     * Changes a template or snippet the person may manage (its own page; GET shows the stored values and version).
     */
    public function edit(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $template = $this->templates->get($id);
            if (!$template->manageable) {
                $this->ui->require(CrmPermission::Manage);
            }
            $form   = $this->form($template->kind, ['name' => $template->name, 'key' => $template->key, 'locale' => $template->locale, 'subject' => $template->subject, 'purpose' => $template->purpose ?? ($template->classified ? EmailTemplateType::SERVICE : null), 'text' => $template->text, 'version' => (string) $template->version], false, true);
            $status = $this->submit($request, $form, function () use ($request, $form, $template): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $version = $form->get('version')->getData();
                $this->templates->update($template->id, $this->input($template->kind, $form, $template->shared), is_numeric($version) ? (int) $version : -1);
                $this->ui->flash($request, 'success', 'template.saved');

                return $this->dialogs->done($request, 'nowo_crm_kit_templates');
            });
            if ($status instanceof Response) {
                return $status;
            }

            return $this->dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_template_edit', ['id' => $template->id]), 'template.edit_' . $template->kind->value, 'template.save', $this->ui->url('nowo_crm_kit_templates'), 'crm-template-edit', $status, intro: $template->hasHtml ? 'template.html_notice' : null);
        });
    }

    /**
     * Deletes a template or snippet shown at `?version=…`.
     */
    #[AsWriteUnit]
    public function delete(Request $request, string $id): Response
    {
        $back = $this->ui->redirect('nowo_crm_kit_templates');

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->ui->form(ActionType::class, null, [], 'crm_action');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw InvalidInputException::field('_', 'error.csrf');
            }
            $version = $request->query->get('version');
            $this->templates->delete($id, is_numeric($version) ? (int) $version : -1);
            $this->ui->flash($request, 'success', 'template.deleted');

            return $back;
        }, $back);
    }

    /**
     * *Write e-mail* to a contact (its own page; GET shows the empty form with a new send key).
     */
    public function write(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $view = $this->reader->record(SubjectType::Contact, $id);
            if ($view->status !== RecordStatus::Active) {
                return $this->ui->toRecord(SubjectType::Contact, $view->mergedIntoId ?? $id);
            }
            $this->ui->requireOn(CrmPermission::Edit, $view);
            $options = $this->writeOptions($view);
            $form    = $this->ui->form(WriteEmailType::class, ['send_key' => MailDraft::newKey(), 'applied' => ''], $options, 'crm_mail');
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            $notice = null;
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                if ($this->clicked($form, 'stay') || $this->clicked($form, 'insert_template') || $this->clicked($form, 'insert_snippet')) {
                    [$form, $notice] = $this->insert($request, $form, $view, $data, $options);
                    $status          = Response::HTTP_OK;
                } else {
                    $sent = $this->dialogs->attempt($request, $form, function () use ($request, $view, $data): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                        $applied = $data['applied'] ?? null;
                        try {
                            $outcome = $this->composer->send(new MailDraft($view->id, $this->text($data, 'subject'), $this->text($data, 'text'), is_string($applied) && $applied !== '' ? $applied : null, $this->text($data, 'send_key')));
                        } catch (RateLimitedException) {
                            throw InvalidInputException::field('_', 'mail_error.rate_limited');
                        }
                        $this->ui->flash($request, $outcome->logged() ? 'success' : 'warning', $outcome->logged() ? 'mail.sent' : 'mail.sent_not_logged');

                        return $this->recordDialogs->done($request, 'nowo_crm_kit_show', ['type' => SubjectType::Contact->segment(), 'id' => $view->id]);
                    });
                    if ($sent instanceof Response) {
                        return $sent;
                    }
                    $status = $sent;
                }
            }

            return $this->recordDialogs->render($request, $form, $this->ui->url('nowo_crm_kit_mail_write', ['id' => $view->id]), 'mail.title', 'mail.send', $this->ui->url('nowo_crm_kit_show', ['type' => SubjectType::Contact->segment(), 'id' => $view->id]), 'crm-mail', $status, ['%name%' => $view->label], templates: ['fragment' => '@NowoCrmKit/templates/_write.html.twig', 'page' => '@NowoCrmKit/templates/write.html.twig'], extra: ['crm_mail_to' => $view->values['email'] ?? null, 'crm_mail_name' => $view->label, 'crm_mail_notice' => $notice]);
        });
    }

    /**
     * The edit and delete actions of a row the person may manage.
     *
     * @return list<Action>
     */
    private function rowActions(EmailTemplateView $template, string $token): array
    {
        if (!$template->manageable) {
            return [];
        }

        return [
            $this->dialogs->opener('edit', 'template.edit', 'nowo_crm_kit_template_edit', ['id' => $template->id], 'crm-template-edit'),
            new Action('delete', UiSupport::label('template.delete'), $this->ui->url('nowo_crm_kit_template_delete', ['id' => $template->id, 'version' => (string) $template->version]), method: 'POST', csrfToken: $token, confirm: new Confirm(UiSupport::label('template.delete_confirm'), null, UiSupport::label('template.delete')), variant: 'danger', attributes: ['data-testid' => 'crm-template-delete'], csrfField: 'crm_action[_token]'),
        ];
    }

    /**
     * The consent column of a row: the purpose of a commercial template, "Service message", "Not classified", or
     * nothing for a snippet.
     */
    private function consent(EmailTemplateView $template): string
    {
        if ($template->kind === EmailTemplateKind::Snippet) {
            return '';
        }
        if (!$template->classified) {
            return $this->ui->trans('template.unclassified');
        }

        return $template->purpose === null ? $this->ui->trans('template.form.service') : $this->ui->trans('consent.purpose.' . $template->purpose);
    }

    /**
     * The form of a kind (`$scope`: offer the scope, a new row only; the organisation's needs `manage`).
     *
     * @param array<string, mixed> $data
     *
     * @return FormInterface<mixed>
     */
    private function form(EmailTemplateKind $kind, array $data, bool $scope, bool $versioned): FormInterface
    {
        $scopes = [];
        if ($scope) {
            $scopes = ['template.scope.personal' => '0'];
            if ($this->ui->can(CrmPermission::Manage)) {
                $scopes['template.scope.shared'] = '1';
            }
        }
        $purposes = [];
        foreach ($this->purposes as $purpose) {
            $purposes['consent.purpose.' . $purpose] = $purpose;
        }
        $placeholders = [];
        foreach ($this->templates->placeholders() as $names) {
            foreach ($names as $name) {
                $placeholders[] = '{{ ' . $name . ' }}';
            }
        }

        return $this->ui->form(EmailTemplateType::class, $data, ['kind' => $kind->value, 'scopes' => $scopes, 'purposes' => $purposes, 'placeholders' => implode(', ', $placeholders), 'versioned' => $versioned], 'crm_template');
    }

    /**
     * Handles a template form: the save's answer, or the status of the form shown again.
     *
     * @param FormInterface<mixed> $form
     * @param callable(): Response $save
     */
    private function submit(Request $request, FormInterface $form, callable $save): Response|int
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return Response::HTTP_OK;
        }

        return $form->isValid() ? $this->dialogs->attempt($request, $form, $save) : Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    /**
     * The domain input of a submitted template form (`$shared`: the stored row's scope, else the form's choice).
     *
     * @param FormInterface<mixed> $form
     */
    private function input(EmailTemplateKind $kind, FormInterface $form, ?bool $shared): EmailTemplateInput
    {
        /** @var array<string, mixed> $data */
        $data    = $form->getData();
        $purpose = $data['purpose'] ?? null;

        return new EmailTemplateInput(
            $kind,
            $shared ?? ($data['shared'] ?? null) === '1',
            $this->text($data, 'key'),
            $this->text($data, 'name'),
            $this->text($data, 'locale'),
            $this->text($data, 'subject'),
            $this->text($data, 'text'),
            is_string($purpose) && $purpose !== EmailTemplateType::SERVICE ? $purpose : null,
        );
    }

    /**
     * The pickers of *Write e-mail*: the person's classified templates, the snippets and the contact's open deals.
     *
     * @return array{templates: array<string, string>, snippets: array<string, string>, deals: array<string, string>}
     */
    private function writeOptions(RecordView $view): array
    {
        $templates = [];
        $snippets  = [];
        foreach ($this->templates->list() as $template) {
            $label = $template->name . ' (' . $template->locale . ($template->shared ? ', ' . $this->ui->trans('template.scope.shared') : '') . ')';
            if ($template->kind === EmailTemplateKind::Snippet) {
                $snippets[$label] = $template->id;
            } elseif ($template->classified) {
                $templates[$label] = $template->id;
            }
        }
        $deals = [];
        if ($this->deals instanceof DealReader) {
            foreach ($this->deals->ofRecord(SubjectType::Contact, $view->id) as $deal) {
                if ($deal->status === DealStatus::Open) {
                    $deals[$deal->title] = $deal->id;
                }
            }
        }

        return ['templates' => $templates, 'snippets' => $snippets, 'deals' => $deals];
    }

    /**
     * Whether a button of the form was the one clicked.
     *
     * @param FormInterface<mixed> $form
     */
    private function clicked(FormInterface $form, string $button): bool
    {
        if (!$form->has($button)) {
            return false;
        }
        $child = $form->get($button);

        return $child instanceof ClickableInterface && $child->isClicked();
    }

    /**
     * Inserts the chosen template (subject and text, once per choice) or snippet (appended to the text) rendered for the
     * contact; a new form with the result and the notice of placeholders left as text.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     *
     * @return array{FormInterface<mixed>, string|null}
     */
    private function insert(Request $request, FormInterface $form, RecordView $view, array $data, array $options): array
    {
        $deal     = is_string($data['deal'] ?? null) ? $data['deal'] : null;
        $template = is_string($data['template'] ?? null) ? $data['template'] : null;
        $snippet  = is_string($data['snippet'] ?? null) ? $data['snippet'] : null;
        $notice   = null;
        if ($this->clicked($form, 'insert_template') && $template !== null && $template !== ($data['applied'] ?? null)) {
            $text            = $this->templates->compose($template, $view->id, $deal);
            $data['subject'] = $text->subject;
            $data['text']    = $text->text;
            $data['applied'] = $template;
            $notice          = $text->unresolved === [] ? null : $this->ui->trans('mail.unresolved', ['%placeholders%' => implode(', ', $text->unresolved)]);
        } elseif ($this->clicked($form, 'insert_snippet') && $snippet !== null) {
            $text         = $this->templates->compose($snippet, $view->id, $deal);
            $current      = rtrim($this->text($data, 'text'));
            $data['text'] = $current === '' ? $text->text : $current . "\n\n" . $text->text;
            $notice       = $text->unresolved === [] ? null : $this->ui->trans('mail.unresolved', ['%placeholders%' => implode(', ', $text->unresolved)]);
        }
        $data['snippet'] = null;

        return [$this->ui->form(WriteEmailType::class, $data, $options, 'crm_mail'), $notice];
    }

    /**
     * A string value of submitted data ('' when absent).
     *
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $name): string
    {
        $value = $data[$name] ?? null;

        return is_string($value) ? $value : '';
    }
}
