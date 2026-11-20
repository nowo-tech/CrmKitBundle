<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Operation\CrmApiCursorCodec;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\PartyStageService;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageView;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\DealFilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\DealType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\LifecycleType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NextActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\StageMoveType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\PageStatus;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function trim;

/**
 * The deal pages (0.3.0, `ui.enabled` and `pipeline.enabled`): the list (keyset pages), the board of a funnel, a deal
 * (stage history, next actions, stage move, trash / restore / purge), new / edit, and the next-action and lifecycle
 * actions of the 360° page. Every POST is CSRF-protected and runs in a write unit. Stateless.
 */
final readonly class PipelineController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private FunnelReader $funnels,
        private DealReader $reader,
        private DealService $deals,
        private NextActionService $actions,
        private PartyStageService $lifecycle,
        private PipelineForms $forms,
        private CrmAccess $access,
        private CrmApiCursorCodec $cursors,
    ) {
    }

    /**
     * The deals: funnel, status, text, stale and order filters; keyset pages (`after`).
     */
    public function list(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnels = $this->funnels->funnels(FunnelKind::Deal, true);
            $amount  = $this->ui->can(CrmPermission::AmountsView);
            $choices = [];
            foreach ($funnels as $f) {
                $choices[$f->name] = $f->id;
            }
            $filters = $this->ui->form(DealFilterType::class, null, ['funnels' => $choices, 'sorts' => ['deal.sort.updated' => 'updated', 'deal.sort.expected_close' => 'expected_close'] + ($amount ? ['deal.sort.amount' => 'amount'] : [])], '');
            $filters->handleRequest($request);
            $status = DealStatus::tryFrom((string) $request->query->get('status', ''));
            $sort   = (string) $request->query->get('sort', DealQuery::SORT_UPDATED);
            $sort   = $sort === DealQuery::SORT_EXPECTED_CLOSE || ($sort === DealQuery::SORT_AMOUNT && $amount) ? $sort : DealQuery::SORT_UPDATED;
            $funnel = (string) $request->query->get('funnel', '');
            $query  = new DealQuery(
                funnelId: $funnel === '' ? null : $funnel,
                status: $status,
                text: trim((string) $request->query->get('q', '')),
                staleOnly: $request->query->get('stale') === '1',
                sort: $sort,
            );
            $keyed = ['funnel' => $query->funnelId, 'status' => $query->status?->value, 'q' => $query->text, 'stale' => $query->staleOnly, 'sort' => $query->sort];
            $page  = $this->reader->deals($query, $this->after((string) $request->query->get('after', ''), $keyed), $this->ui->pageSize);
            $names = [];
            foreach ($funnels as $f) {
                foreach ($f->stages as $s) {
                    $names[$s->id] = $s->name;
                }
            }
            $rows = [];
            foreach ($page->deals as $deal) {
                $rows[] = [
                    'id'      => $deal->id,
                    'title'   => $deal->title,
                    'stage'   => $names[$deal->stageId] ?? '',
                    'company' => $deal->companyLabel ?? '',
                    'contact' => $deal->contactLabel ?? '',
                    'owner'   => $deal->ownerName ?? $deal->ownerId ?? '',
                    'amount'  => $deal->amount === null ? '' : $deal->amount->amount . ' ' . $deal->amount->currency,
                    'close'   => $deal->expectedClose === null ? '' : $deal->expectedClose->value,
                    'stale'   => $deal->stale ? $this->ui->trans('deal.stale') : '',
                ];
            }
            $columns = [new Column('title', UiSupport::label('deal.form.title'), required: true), new Column('stage', UiSupport::label('deal.form.stage')), new Column('company', UiSupport::label('deal.form.company'), priority: 2), new Column('contact', UiSupport::label('deal.form.contact'), priority: 3), new Column('owner', UiSupport::label('deal.form.owner'), priority: 3)];
            if ($amount) {
                $columns[] = new Column('amount', UiSupport::label('deal.form.amount'), align: 'end', priority: 2);
            }
            $columns[] = new Column('close', UiSupport::label('deal.form.expected_close'), priority: 3);
            $columns[] = new Column('stale', UiSupport::label('deal.stale'), priority: 3);
            $actions   = $this->ui->can(CrmPermission::Create) && $funnels !== [] ? [Action::create($this->ui->url('nowo_crm_kit_deal_new'), UiSupport::label('deal.new'))] : [];
            $next      = $page->next === null ? null : $this->ui->url('nowo_crm_kit_deals', ['after' => $this->cursor($page->next, $keyed)] + $request->query->all());

            return $this->ui->render('@NowoCrmKit/pipeline/deals.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('deals.title'), null, $actions, tabs: $this->ui->tabs('deals')),
                    $columns,
                    $rows,
                    $filters->createView(),
                    fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::view($this->ui->url('nowo_crm_kit_deal_show', ['id' => $row['id']]), UiSupport::label('record.open'))] : [],
                    empty: new EmptyState(UiSupport::label('deals.empty')),
                    filtersClearHref: $this->ui->url('nowo_crm_kit_deals'),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-deals'],
                    responsive: 'cards',
                ),
                'funnels' => $funnels,
                'next'    => $next,
            ]);
        });
    }

    /**
     * The board of a deal funnel: one column per stage with its open, won or lost deals.
     */
    public function board(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnel  = $this->dealFunnel($id);
            $deals   = $this->reader->deals(new DealQuery(funnelId: $id), null, DealReader::MAX_PAGE);
            $byStage = [];
            foreach ($deals->deals as $deal) {
                $byStage[$deal->stageId][] = $deal;
            }

            return $this->ui->render('@NowoCrmKit/pipeline/board.html.twig', [
                'page'    => $this->showPage(new PageHeader($funnel->name, UiSupport::label('board.intro'), $this->funnelActions($funnel), tabs: $this->ui->tabs('deals')), [], 'crm-board'),
                'funnel'  => $funnel,
                'columns' => $byStage,
                'more'    => $deals->next !== null,
            ]);
        });
    }

    /**
     * A new deal (`?funnel=`, `?company=`, `?contact=` preselect).
     */
    #[AsWriteUnit]
    public function create(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Create);
            $funnels = $this->funnels->funnels(FunnelKind::Deal);
            $stages  = [];
            foreach ($funnels as $funnel) {
                if (!$funnel->archived) {
                    foreach ($this->forms->stages($funnel, true) as $label => $stageId) {
                        $stages[$funnel->name . ' › ' . $label] = $funnel->id . '|' . $stageId;
                    }
                }
            }
            if ($stages === []) {
                throw new NotAvailableException();
            }
            $amounts = $this->ui->can(CrmPermission::AmountsView);
            $wanted  = (string) $request->query->get('funnel', '');
            $first   = null;
            foreach ($stages as $value) {
                $first ??= $value;
                $first = $wanted !== '' && explode('|', $value)[0] === $wanted && explode('|', $first)[0] !== $wanted ? $value : $first;
            }
            $data = ['stage' => $first, 'company' => $request->query->get('company'), 'contact' => $request->query->get('contact')];
            $form = $this->ui->form(DealType::class, $data, $this->dealOptions($amounts) + ['stages' => $stages], 'crm_deal');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    [$funnelId, $stageId] = explode('|', $this->text($form, 'stage') . '|');
                    $id                   = $this->deals->create($this->input($form, $amounts, $funnelId, $stageId));
                    $this->ui->flash($request, 'success', 'deal.created');

                    return $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]);
                } catch (InvalidInputException $e) {
                    $this->ui->errors($form, $e);
                } catch (ConflictException $e) {
                    $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                    return $this->formPage($form, null, Response::HTTP_CONFLICT);
                }
            }

            return $this->formPage($form, null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * A deal: details, stage move, stage history, next actions, timeline.
     */
    public function show(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal    = $this->reader->deal($id, true);
            $funnel  = $this->funnels->funnel($deal->funnelId);
            $subject = SubjectRef::of(SubjectType::Deal, $deal->id, $deal->ownerId);
            $open    = $deal->status !== DealStatus::Trashed;
            $edit    = $open && $this->access->can(CrmPermission::Edit, $subject);
            $delete  = $this->access->can(CrmPermission::Delete, $subject);
            $token   = $this->ui->actionToken();
            $actions = [];
            if ($edit) {
                $actions[] = Action::edit($this->ui->url('nowo_crm_kit_deal_edit', ['id' => $id]), UiSupport::label('deal.edit'));
            }
            if ($open && $delete) {
                $actions[] = $this->post($token, 'delete', 'deal.trash', 'nowo_crm_kit_deal_trash', ['id' => $id], 'deal.trash_confirm', 'danger');
            }
            if (!$open && $delete) {
                $actions[] = $this->post($token, 'restore', 'deal.restore', 'nowo_crm_kit_deal_restore', ['id' => $id]);
            }
            if (!$open && $this->access->can(CrmPermission::Manage, $subject)) {
                $actions[] = $this->post($token, 'delete', 'deal.purge', 'nowo_crm_kit_deal_purge', ['id' => $id], 'deal.purge_confirm', 'danger');
            }
            $stage   = $funnel->stage($deal->stageId);
            $details = [
                new DetailItem(UiSupport::label('deal.funnel'), $funnel->name, $this->ui->url('nowo_crm_kit_deal_board', ['id' => $funnel->id])),
                new DetailItem(UiSupport::label('deal.form.stage'), $stage instanceof StageView ? $stage->name : ''),
                new DetailItem(UiSupport::label('deal.status_label'), $this->ui->trans('deal.status.' . $deal->status->value)),
                new DetailItem(UiSupport::label('deal.form.company'), $deal->companyLabel ?? $this->ui->trans($deal->companyHidden ? 'deal.hidden' : 'field.owner_none'), $deal->companyId === null ? null : $this->ui->url('nowo_crm_kit_show', ['type' => 'companies', 'id' => $deal->companyId])),
                new DetailItem(UiSupport::label('deal.form.contact'), $deal->contactLabel ?? $this->ui->trans($deal->contactHidden ? 'deal.hidden' : 'field.owner_none'), $deal->contactId === null ? null : $this->ui->url('nowo_crm_kit_show', ['type' => 'contacts', 'id' => $deal->contactId])),
                new DetailItem(UiSupport::label('deal.form.owner'), $deal->ownerName ?? $deal->ownerId ?? $this->ui->trans('field.owner_none')),
                new DetailItem(UiSupport::label('deal.probability'), $deal->effectiveProbability . ' %'),
            ];
            if (!$deal->amountsHidden && $deal->amount instanceof Money) {
                $details[] = new DetailItem(UiSupport::label('deal.form.amount'), $deal->amount->amount . ' ' . $deal->amount->currency);
                $details[] = new DetailItem(UiSupport::label('deal.weighted'), $deal->weightedValue . ' ' . $deal->amount->currency);
            }
            if ($deal->expectedClose instanceof DateImmutable) {
                $details[] = new DetailItem(UiSupport::label('deal.form.expected_close'), $deal->expectedClose->value);
            }
            if ($deal->lostReason !== null) {
                $details[] = new DetailItem(UiSupport::label('deal.move.lost_reason'), $this->ui->trans('lost_reason.' . $deal->lostReason));
            }
            if ($deal->lostNote !== null) {
                $details[] = new DetailItem(UiSupport::label('deal.move.lost_note'), $deal->lostNote);
            }
            $status = match (true) {
                !$open       => new PageStatus(UiSupport::label('status.trashed'), 'warning'),
                $deal->stale => new PageStatus(UiSupport::label('deal.stale'), 'warning'),
                default      => null,
            };
            $forms = [];
            if ($edit) {
                $forms['move']   = $this->ui->form(StageMoveType::class, ['stage' => $deal->stageId, 'version' => (string) $deal->version], ['stages' => $this->forms->stages($funnel), 'reasons' => $this->forms->reasons()], 'crm_stage_move')->createView();
                $forms['action'] = $this->ui->form(NextActionType::class, ['dedupe' => PipelineForms::dedupe(), 'due_at' => (new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC')))], ['owners' => $this->forms->owners()], 'crm_next_action')->createView();
            }
            $buttons = [];
            if ($edit) {
                foreach ($deal->nextActions as $action) {
                    $buttons[$action->id] = [
                        $this->post($token, 'custom', 'next_action.done', 'nowo_crm_kit_next_action_close', ['id' => $action->id, 'action' => 'done', 'back_type' => 'deals', 'back' => $id]),
                        $this->post($token, 'custom', 'next_action.cancel', 'nowo_crm_kit_next_action_close', ['id' => $action->id, 'action' => 'cancel', 'back_type' => 'deals', 'back' => $id]),
                    ];
                }
            }

            return $this->ui->render('@NowoCrmKit/pipeline/deal.html.twig', [
                'page'    => $this->showPage(new PageHeader($deal->title, UiSupport::label('type.deal'), $actions, tabs: $this->ui->tabs('deals'), status: $status), [new DetailSection(UiSupport::label('record.details'), $details, 'crm-deal-details')], 'crm-deal'),
                'deal'    => $deal,
                'funnel'  => $funnel,
                'forms'   => $forms,
                'buttons' => $buttons,
            ]);
        });
    }

    /**
     * Edits a deal (the version guards against a lost update).
     */
    #[AsWriteUnit]
    public function edit(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->reader->deal($id);
            $this->ui->requireSubject(CrmPermission::Edit, SubjectRef::of(SubjectType::Deal, $deal->id, $deal->ownerId));
            $amounts = !$deal->amountsHidden;
            $data    = ['title' => $deal->title, 'company' => $deal->companyId, 'contact' => $deal->contactId, 'owner' => $deal->ownerId, 'expected_close' => $deal->expectedClose?->value, 'version' => (string) $deal->version];
            if ($amounts) {
                $data += ['amount' => $deal->amount?->amount, 'currency' => $deal->amount?->currency, 'probability' => $deal->probability];
            }
            $options = $this->dealOptions($amounts);
            foreach (['companies' => [$deal->companyLabel, $deal->companyId], 'contacts' => [$deal->contactLabel, $deal->contactId]] as $key => [$label, $current]) {
                if ($label !== null && $current !== null && !in_array($current, $options[$key], true)) {
                    $options[$key][$label] = $current;
                }
            }
            $form = $this->ui->form(DealType::class, $data, $options + ['versioned' => true], 'crm_deal');
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $input = $this->input($form, $amounts, null, null);
                    $this->deals->update($id, new DealInput($input->title, null, null, $input->companyId, $input->contactId, $input->ownerId, $input->amount, $input->probability, $input->expectedClose, $amounts ? DealInput::FIELDS : ['title', 'company_id', 'contact_id', 'owner', 'expected_close']), $this->int($form, 'version'));
                    $this->ui->flash($request, 'success', 'deal.saved');

                    return $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]);
                } catch (InvalidInputException $e) {
                    $this->ui->errors($form, $e);
                } catch (ConflictException $e) {
                    $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                    return $this->formPage($form, $deal, Response::HTTP_CONFLICT);
                }
            }

            return $this->formPage($form, $deal, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        });
    }

    /**
     * Moves a deal to a stage (POST).
     */
    #[AsWriteUnit]
    public function move(Request $request, string $id): Response
    {
        return $this->ui->act($request, function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->ui->form(StageMoveType::class, null, ['stages' => ['_' => $this->field($request, 'crm_stage_move', 'stage')], 'reasons' => $this->forms->reasons()], 'crm_stage_move');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw InvalidInputException::field('_', 'error.csrf');
            }
            $reason = $this->text($form, 'lost_reason');
            $moved  = $this->deals->moveStage($id, $this->text($form, 'stage'), $reason === '' ? null : $reason, $this->text($form, 'lost_note'), $this->int($form, 'version'));
            $this->ui->flash($request, 'success', $moved ? 'deal.moved' : 'deal.not_moved');

            return $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]);
        }, $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]));
    }

    /**
     * Moves a deal to the trash (POST).
     */
    #[AsWriteUnit]
    public function trash(Request $request, string $id): Response
    {
        return $this->ui->act($request, function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $this->deals->trash($id);
            $this->ui->flash($request, 'success', 'deal.trashed');

            return $this->ui->redirect('nowo_crm_kit_deals');
        }, $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]));
    }

    /**
     * Brings a deal back from the trash (POST).
     */
    #[AsWriteUnit]
    public function restore(Request $request, string $id): Response
    {
        return $this->ui->act($request, function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $this->deals->restore($id);
            $this->ui->flash($request, 'success', 'deal.restored');

            return $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]);
        }, $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]));
    }

    /**
     * Deletes a trashed deal for good (POST, `manage`).
     */
    #[AsWriteUnit]
    public function purge(Request $request, string $id): Response
    {
        return $this->ui->act($request, function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $this->deals->purge($id);
            $this->ui->flash($request, 'success', 'deal.purged');

            return $this->ui->redirect('nowo_crm_kit_deals', ['status' => 'trashed']);
        }, $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]));
    }

    /**
     * Adds a next action to a company, contact or deal (POST).
     */
    public function addNextAction(Request $request, string $type, string $id): Response
    {
        $subject = $this->subject($type);
        $back    = $this->back($subject, $id);

        return $this->ui->act($request, function () use ($request, $subject, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->ui->form(NextActionType::class, null, ['owners' => $this->forms->owners()], 'crm_next_action');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw InvalidInputException::field('_', 'error.next_action_invalid');
            }
            $due = $form->get('due_at')->getData();
            if (!$due instanceof DateTimeImmutable) {
                throw InvalidInputException::field('due_at', 'error.required');
            }
            $assignee = $this->text($form, 'assignee');
            try {
                $this->actions->set($subject, $id, $this->text($form, 'title'), $due, $assignee === '' ? null : $assignee, $this->text($form, 'dedupe'), null, $back->getTargetUrl()); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            } catch (CrmTaskRefusedException) {
                $this->ui->flash($request, 'error', 'next_action.refused');

                return $back;
            }
            $this->ui->flash($request, 'success', 'next_action.added');

            return $back;
        }, $back);
    }

    /**
     * Marks a next action done or cancels it (POST).
     */
    #[AsWriteUnit]
    public function closeNextAction(Request $request, string $id, string $action): Response
    {
        return $this->ui->act($request, function () use ($request, $id, $action): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $action === 'done' ? $this->actions->complete($id) : $this->actions->cancel($id);
            $this->ui->flash($request, 'success', 'next_action.closed');

            return $this->back(null, null, $request);
        }, $this->back(null, null, $request));
    }

    /**
     * Sets the lifecycle stage of a company or contact (POST).
     */
    #[AsWriteUnit]
    public function lifecycle(Request $request, string $type, string $id): Response
    {
        $subject = SubjectType::fromSegment($type) ?? throw new NotFoundHttpException();
        $back    = $this->ui->toRecord($subject, $id);

        return $this->ui->act($request, function () use ($request, $subject, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $value = $this->field($request, 'crm_lifecycle', 'stage');
            $form  = $this->ui->form(LifecycleType::class, null, ['stages' => ['_' => $value]], 'crm_lifecycle');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid() || preg_match('/\A([0-9a-f-]{36})\|([0-9a-f-]{36})\z/', $value, $m) !== 1) {
                throw InvalidInputException::field('_', 'error.csrf');
            }
            $this->lifecycle->set($subject, $id, $m[1], $m[2]); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->ui->flash($request, 'success', 'lifecycle.saved');

            return $back;
        }, $back);
    }

    /**
     * The key of a keyset page in a URL (0.3.0, audit VS-3): sealed with the API's cursor codec and bound to the
     * list's filters, so no value of a row reaches the URL.
     *
     * @param array{v: string|null, id: string} $key
     * @param array<string, mixed> $filters
     */
    public function cursor(array $key, array $filters): string
    {
        return $this->cursors->seal(CrmApiOperation::DealSearch, CrmApiCursorCodec::filterHash(['ui' => $filters]), ['v' => $key['v'], 'id' => $key['id']]);
    }

    /**
     * The key of the `after` parameter, or null when absent, tampered with or for other filters (the list starts
     * again).
     *
     * @param array<string, mixed> $filters
     *
     * @return array{v: string|null, id: string}|null
     */
    public function after(string $cursor, array $filters): ?array
    {
        try {
            $key = $this->cursors->open(new CrmApiCursor($cursor), CrmApiOperation::DealSearch, CrmApiCursorCodec::filterHash(['ui' => $filters]));
        } catch (CrmApiRefusal) {
            return null;
        }
        $v = $key['v'] ?? null;

        return is_string($key['id'] ?? null) && ($v === null || is_string($v)) ? ['v' => $v, 'id' => $key['id']] : null;
    }

    /**
     * The choices of the deal form.
     *
     * @return array{companies: array<string, string>, contacts: array<string, string>, owners: array<string, string>, amounts: bool}
     */
    private function dealOptions(bool $amounts): array
    {
        return ['companies' => $this->forms->records(SubjectType::Company), 'contacts' => $this->forms->records(SubjectType::Contact), 'owners' => $this->forms->owners(), 'amounts' => $amounts];
    }

    /**
     * The input of the deal form.
     *
     * @param FormInterface<mixed> $form
     */
    private function input(FormInterface $form, bool $amounts, ?string $funnelId, ?string $stageId): DealInput
    {
        $money = null;
        if ($amounts) {
            $amount   = trim($this->text($form, 'amount'));
            $currency = trim($this->text($form, 'currency'));
            if ($amount !== '' || $currency !== '') {
                if (!Money::isValid($amount, $currency)) {
                    throw InvalidInputException::field('amount', 'error.amount_invalid');
                }
                $money = new Money($amount, $currency);
            }
        }
        $close = $this->text($form, 'expected_close');
        $value = $form->has('probability') ? $form->get('probability')->getData() : null;

        return new DealInput(
            $this->text($form, 'title'),
            $funnelId,
            $stageId,
            $this->nullable($form, 'company'),
            $this->nullable($form, 'contact'),
            $this->nullable($form, 'owner'),
            $money,
            is_numeric($value) ? (int) $value : null,
            $close === '' ? null : new DateImmutable($close),
        );
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function formPage(FormInterface $form, ?DealView $deal, int $status): Response
    {
        return $this->ui->render('@NowoCrmKit/record/form.html.twig', [
            'page' => new FormPage(
                new PageHeader($deal instanceof DealView ? UiSupport::label('deal.edit_title', ['%name%' => $deal->title]) : UiSupport::label('deal.new'), null, [], tabs: $this->ui->tabs('deals')),
                $form->createView(),
                UiSupport::label('form.save'),
                $deal instanceof DealView ? $this->ui->url('nowo_crm_kit_deal_show', ['id' => $deal->id]) : $this->ui->url('nowo_crm_kit_deals'),
                UiSupport::label('form.cancel'),
                bundle: UiSupport::DOMAIN,
                layout: $this->ui->layout(),
                attributes: ['data-testid' => 'crm-deal-form'],
            ),
        ], $status);
    }

    /**
     * @param list<DetailSection> $sections
     */
    private function showPage(PageHeader $header, array $sections, string $testid): ShowPage
    {
        return new ShowPage($header, $sections, bundle: UiSupport::DOMAIN, layout: $this->ui->layout(), attributes: ['data-testid' => $testid]);
    }

    /**
     * The actions of a funnel's header: a new deal, the statistics.
     *
     * @return list<Action>
     */
    private function funnelActions(FunnelView $funnel): array
    {
        $actions = [];
        if (!$funnel->archived && $this->ui->can(CrmPermission::Create)) {
            $actions[] = Action::create($this->ui->url('nowo_crm_kit_deal_new', ['funnel' => $funnel->id]), UiSupport::label('deal.new'));
        }
        $actions[] = new Action('custom', UiSupport::label('stats.title'), $this->ui->url('nowo_crm_kit_funnel_stats', ['id' => $funnel->id]), icon: 'chart');

        return $actions;
    }

    /**
     * A deal funnel the actor may view.
     */
    private function dealFunnel(string $id): FunnelView
    {
        $funnel = $this->funnels->funnel($id);

        return $funnel->kind === FunnelKind::Deal ? $funnel : throw new NotAvailableException();
    }

    /**
     * Where a POST of a subject returns: the deal or the record page (`?subject_type=&subject=` for a next action).
     */
    private function back(?SubjectType $type, ?string $id, ?Request $request = null): RedirectResponse
    {
        if ($request instanceof Request) {
            $type = $this->subject((string) $request->query->get('back_type', 'deals'));
            $id   = (string) $request->query->get('back', '');
        }
        if ($id === null || preg_match('/\A[0-9a-f-]{36}\z/', $id) !== 1) {
            return $this->ui->redirect('nowo_crm_kit_deals');
        }

        return $type === SubjectType::Deal ? $this->ui->redirect('nowo_crm_kit_deal_show', ['id' => $id]) : $this->ui->toRecord($type ?? SubjectType::Company, $id);
    }

    /**
     * A POST action of the page (CSRF token of `crm_action`).
     *
     * @param array<string, string> $params
     */
    private function post(string $token, string $kind, string $label, string $route, array $params, ?string $confirm = null, ?string $variant = null): Action
    {
        return new Action($kind, UiSupport::label($label), $this->ui->url($route, $params), method: 'POST', csrfToken: $token, confirm: $confirm === null ? null : new Confirm(UiSupport::label($confirm), null, UiSupport::label($label)), variant: $variant, csrfField: 'crm_action[_token]');
    }

    private function csrf(Request $request): void
    {
        $form = $this->ui->form(ActionType::class, null, [], 'crm_action');
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw InvalidInputException::field('_', 'error.csrf');
        }
    }

    /**
     * A posted field of a named form, for choice lists built from what was posted (the services check it).
     */
    private function field(Request $request, string $form, string $field): string
    {
        $data = $request->request->all($form);

        return is_string($data[$field] ?? null) ? $data[$field] : '';
    }

    private function subject(string $segment): SubjectType
    {
        return $segment === 'deals' ? SubjectType::Deal : (SubjectType::fromSegment($segment) ?? throw new NotFoundHttpException());
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function text(FormInterface $form, string $field): string
    {
        $value = $form->has($field) ? $form->get($field)->getData() : null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function nullable(FormInterface $form, string $field): ?string
    {
        $value = $this->text($form, $field);

        return $value === '' ? null : $value;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function int(FormInterface $form, string $field): int
    {
        $value = $this->text($form, $field);

        return is_numeric($value) ? (int) $value : 0;
    }
}
