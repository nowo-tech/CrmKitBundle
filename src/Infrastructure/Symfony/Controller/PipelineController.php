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
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
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
 * The deal pages (0.3.0, `ui.enabled` and `pipeline.enabled`): the list (0.10.2: numbered pages), the board of a
 * funnel, a deal (stage history, next actions, stage move, trash / restore / purge), new / edit, and the next-action
 * and lifecycle actions of the 360° page. Every POST is CSRF-protected and runs in a write unit. Stateless.
 */
final readonly class PipelineController
{
    /**
     * The form child of a domain field of a deal (0.8.1): the service names `company_id` / `contact_id`, the form
     * `company` / `contact`; without the map the error went to the form root and the 422 showed no message.
     */
    private const DEAL_FIELDS = ['company_id' => 'company', 'contact_id' => 'contact'];

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
        private ?PipelineDialogs $dialogs = null,
        private ?CrmReaderInterface $records = null,
    ) {
    }

    /**
     * The deals: the filters of the Beacon filter standard and sortable headers; 0.10.2: numbered pages with the total
     * (the `after` cursor of 0.3.0 - 0.10.1 is ignored).
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
            $stages = [];
            foreach ($funnels as $f) {
                foreach ($f->stages as $st) {
                    if (!$st->archived) {
                        $stages[$f->name . ' · ' . $st->name] = $st->id;
                    }
                }
            }
            $owners = $this->ui->ownerChoices($this->forms->owners());
            // 0.10.2 (Beacon filter standard): owner, stage, company, contact, expected close range, rows per page;
            // the order comes from the sortable column headers.
            $filters = $this->ui->form(DealFilterType::class, null, ['funnels' => $choices, 'owners' => $owners, 'stages' => $stages, 'companies' => $this->forms->records(SubjectType::Company), 'contacts' => $this->forms->records(SubjectType::Contact), 'per_page' => $this->ui->filterPerPage()], '');
            $filters->handleRequest($request);
            $q      = $request->query->all();
            $status = DealStatus::tryFrom(is_string($q['status'] ?? null) ? $q['status'] : '');
            $sort   = is_string($q['sort'] ?? null) && isset(DealQuery::SORTS[$q['sort']]) ? $q['sort'] : DealQuery::SORT_UPDATED;
            $sort   = $sort === DealQuery::SORT_AMOUNT && !$amount ? DealQuery::SORT_UPDATED : $sort;
            $funnel = is_string($q['funnel'] ?? null) ? $q['funnel'] : '';
            $owner  = is_string($q['owner'] ?? null) ? $q['owner'] : '';
            $pick   = static fn (string $key, array $allowed): ?string => is_string($q[$key] ?? null) && in_array($q[$key], $allowed, true) ? $q[$key] : null;
            $query  = new DealQuery(
                funnelId: $funnel === '' ? null : $funnel,
                stageIds: ($stage = $pick('stage', $stages)) === null ? null : [$stage],
                status: $status,
                ownerId: $owner === '' || in_array($owner, [ListFilters::OWNER_ME, ListFilters::OWNER_NONE], true) ? null : $owner,
                companyId: is_string($q['company'] ?? null) && RecordId::isValid($q['company']) ? $q['company'] : null,
                contactId: is_string($q['contact'] ?? null) && RecordId::isValid($q['contact']) ? $q['contact'] : null,
                text: trim(is_string($q['q'] ?? null) ? $q['q'] : ''),
                staleOnly: ($q['stale'] ?? null) === '1',
                sort: $sort,
                direction: is_string($q['direction'] ?? null) ? $q['direction'] : null,
                ownerMe: $owner === ListFilters::OWNER_ME,
                ownerNone: $owner === ListFilters::OWNER_NONE,
                expectedCloseFrom: ListFilters::day($q['expected_close_from'] ?? null),
                expectedCloseTo: ListFilters::day($q['expected_close_to'] ?? null),
            );
            $perPage = $this->ui->options->perPage($q['per_page'] ?? null, $this->ui->pageSize);
            // 0.10.2: numbered pages with the total ("Showing 1–25 of N"), like the company and contact lists; a page
            // past the end shows the last page.
            $number = is_numeric($q['page'] ?? null) ? max(1, (int) $q['page']) : 1;
            $page   = $this->reader->page($query, $number, $perPage);
            if ($page->deals === [] && $number > 1 && $page->total > 0) {
                $number = intdiv($page->total - 1, $perPage) + 1;
                $page   = $this->reader->page($query, $number, $perPage);
            }
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
                    'updated' => $deal->updatedAt->format('Y-m-d'),
                    'stale'   => $deal->stale ? $this->ui->trans('deal.stale') : '',
                ];
            }
            // 0.10.2: title, amount, expected close and updated sort from their headers (server side, both directions).
            $columns = [new Column('title', UiSupport::label('deal.form.title'), true, required: true, sortKey: DealQuery::SORT_TITLE), new Column('stage', UiSupport::label('deal.form.stage')), new Column('company', UiSupport::label('deal.form.company'), priority: 2), new Column('contact', UiSupport::label('deal.form.contact'), priority: 3), new Column('owner', UiSupport::label('deal.form.owner'), priority: 3)];
            if ($amount) {
                $columns[] = new Column('amount', UiSupport::label('deal.form.amount'), true, align: 'end', priority: 2, sortKey: DealQuery::SORT_AMOUNT, sortFirst: 'desc');
            }
            $columns[] = new Column('close', UiSupport::label('deal.form.expected_close'), true, priority: 3, sortKey: DealQuery::SORT_EXPECTED_CLOSE);
            $columns[] = new Column('updated', UiSupport::label('list.col.updated'), true, priority: 3, sortKey: DealQuery::SORT_UPDATED, sortFirst: 'desc');
            $columns[] = new Column('stale', UiSupport::label('deal.stale'), priority: 3);
            $create    = $this->ui->can(CrmPermission::Create) && $funnels !== [] ? Action::create($this->ui->url('nowo_crm_kit_deal_new'), UiSupport::label('deal.new')) : null;
            // 0.10.2 (Beacon UI-PATTERNS §11): one create button — the empty state's on an empty, unfiltered list.
            $actions = $create instanceof Action && ($rows !== [] || ListFilters::any($q) || $number > 1) ? [$create] : [];

            return $this->ui->render('@NowoCrmKit/pipeline/deals.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('deals.title'), null, $actions, tabs: $this->ui->tabs('deals')),
                    $columns,
                    $rows,
                    $this->ui->filterView($filters, $perPage),
                    fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::view($this->ui->url('nowo_crm_kit_deal_show', ['id' => $row['id']]), UiSupport::label('record.open'))] : [],
                    pagination: $this->ui->pagination($page->total, $number, $perPage, 'nowo_crm_kit_deals', $request),
                    empty: ListFilters::any($q) ? new EmptyState(null, UiSupport::label('list.no_match_filters'), new Action('custom', UiSupport::label('list.filter.clear'), $this->ui->url('nowo_crm_kit_deals'), icon: 'filter_off', variant: 'secondary', attributes: ['data-testid' => 'crm-list-clear-filters'])) : $this->dealsEmpty($funnels !== [], $create),
                    filtersClearHref: $this->ui->url('nowo_crm_kit_deals'),
                    sort: $query->sort === DealQuery::SORT_EXPECTED_CLOSE ? 'close' : $query->sort,
                    direction: $query->direction,
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-deals'],
                    filterFields: $this->ui->filterFields('deals', UiSupport::label('deal.filter.q_placeholder'), ['aria-label' => $this->ui->trans('deal.filter.q_aria')]),
                    responsive: 'cards',
                    filtersFold: $this->ui->options->filtersFold ? true : null,
                ),
                'funnels' => $funnels,
                'next'    => null,
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
                    $this->ui->errors($form, $e, self::DEAL_FIELDS);
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
            $dialogs = $this->dialogs();
            if ($open && $delete) {
                // 0.9.0: with the dialogs the confirm is the trash page itself, loaded on demand (no dialog in the page).
                $actions[] = $dialogs instanceof PipelineDialogs
                    ? $dialogs->opener('delete', 'deal.trash', 'nowo_crm_kit_deal_trash', ['id' => $id], 'crm-deal-trash', 'danger')
                    : $this->post($token, 'delete', 'deal.trash', 'nowo_crm_kit_deal_trash', ['id' => $id], 'deal.trash_confirm', 'danger');
            }
            if (!$open && $delete) {
                $actions[] = $this->post($token, 'restore', 'deal.restore', 'nowo_crm_kit_deal_restore', ['id' => $id]);
            }
            if (!$open && $this->access->can(CrmPermission::Manage, $subject)) {
                $actions[] = $dialogs instanceof PipelineDialogs
                    ? $dialogs->opener('delete', 'deal.purge', 'nowo_crm_kit_deal_purge', ['id' => $id], 'crm-deal-purge', 'danger')
                    : $this->post($token, 'delete', 'deal.purge', 'nowo_crm_kit_deal_purge', ['id' => $id], 'deal.purge_confirm', 'danger');
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
            $forms   = [];
            $openers = [];
            if ($edit && $dialogs instanceof PipelineDialogs) {
                // 0.9.0: links to the forms' own pages instead of the forms (D-20261123-01).
                $openers['move']   = $dialogs->opener('move', 'deal.move.title', 'nowo_crm_kit_deal_move', ['id' => $id], 'crm-deal-move-open', 'secondary');
                $openers['action'] = $dialogs->opener('create', 'next_action.add', 'nowo_crm_kit_next_action_add', ['type' => 'deals', 'id' => $id], 'crm-next-action-open', 'secondary');
            } elseif ($edit) {
                $forms['move']   = $this->ui->form(StageMoveType::class, ['stage' => $deal->stageId, 'version' => (string) $deal->version], ['stages' => $this->forms->stages($funnel), 'reasons' => $this->forms->reasons()], 'crm_stage_move')->createView();
                $forms['action'] = $this->ui->form(NextActionType::class, ['dedupe' => PipelineForms::dedupe(), 'due_at' => (new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC')))], ['owners' => $this->forms->owners()], 'crm_next_action')->createView();
            }
            $buttons = [];
            if ($edit) {
                foreach ($deal->nextActions as $action) {
                    $buttons[$action->id] = [
                        $this->post($token, 'mark_done', 'next_action.done', 'nowo_crm_kit_next_action_close', ['id' => $action->id, 'action' => 'done', 'back_type' => 'deals', 'back' => $id]),
                        $this->post($token, 'cancel', 'next_action.cancel', 'nowo_crm_kit_next_action_close', ['id' => $action->id, 'action' => 'cancel', 'back_type' => 'deals', 'back' => $id]),
                    ];
                }
            }

            return $this->ui->render('@NowoCrmKit/pipeline/deal.html.twig', [
                'page'    => $this->showPage(new PageHeader($deal->title, UiSupport::label('type.deal'), $actions, tabs: $this->ui->tabs('deals'), status: $status), [new DetailSection(UiSupport::label('record.details'), $details, 'crm-deal-details')], 'crm-deal'),
                'deal'    => $deal,
                'funnel'  => $funnel,
                'forms'   => $forms,
                'buttons' => $buttons,
                // 0.9.0 (`ui.pipeline_forms: dialog`): the openers of the move and next action dialogs and the shell.
                'openers'    => $openers,
                'crm_dialog' => $openers !== [] || ($open && $delete) || (!$open && $this->access->can(CrmPermission::Manage, $subject)) ? $dialogs?->shell('deal.move.title') : null,
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
                    $this->ui->errors($form, $e, self::DEAL_FIELDS);
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
        $dialogs = $this->dialogs();
        if ($dialogs instanceof PipelineDialogs || $request->isMethod('GET')) {
            return $this->movePage($request, $id, $dialogs ?? $this->pageDialogs());
        }

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
        $dialogs = $this->dialogs();
        if ($dialogs instanceof PipelineDialogs || $request->isMethod('GET')) {
            return $this->confirmPage($request, $id, 'trash', $dialogs ?? $this->pageDialogs());
        }

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
        $dialogs = $this->dialogs();
        if ($dialogs instanceof PipelineDialogs || $request->isMethod('GET')) {
            return $this->confirmPage($request, $id, 'purge', $dialogs ?? $this->pageDialogs());
        }

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
        $dialogs = $this->dialogs();
        if ($dialogs instanceof PipelineDialogs || $request->isMethod('GET')) {
            return $this->nextActionPage($request, $subject, $id, $dialogs ?? $this->pageDialogs());
        }
        $back = $this->back($subject, $id);

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
        $dialogs = $this->dialogs();
        if ($dialogs instanceof PipelineDialogs || $request->isMethod('GET')) {
            return $this->lifecyclePage($request, $subject, $id, $dialogs ?? $this->pageDialogs());
        }
        $back = $this->ui->toRecord($subject, $id);

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
     * The empty state of the deals list on its defaults (0.10.2, UI-PATTERNS §11): without a deal funnel, why there are
     * no deals and a link to the funnels for someone who may manage them; else create.
     */
    private function dealsEmpty(bool $hasFunnels, ?Action $create): EmptyState
    {
        if ($hasFunnels) {
            return new EmptyState(null, UiSupport::label('deals.empty_hint'), $create);
        }

        return new EmptyState(null, UiSupport::label('deals.empty_no_funnel'), $this->ui->can(CrmPermission::Manage) ? new Action('custom', UiSupport::label('deals.open_funnels'), $this->ui->url('nowo_crm_kit_funnels'), icon: 'config', variant: 'secondary', attributes: ['data-testid' => 'crm-deals-empty-funnels']) : null);
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
     * The funnel pages' dialogs, or null with `ui.pipeline_forms: inline` (0.9.0).
     */
    private function dialogs(): ?PipelineDialogs
    {
        return $this->dialogs instanceof PipelineDialogs && $this->dialogs->enabled() ? $this->dialogs : null;
    }

    /**
     * The renderer of a form's own page with inline forms (its GET: a deep link, the no-JS page), 404 without it.
     */
    private function pageDialogs(): PipelineDialogs
    {
        return $this->dialogs ?? throw new NotFoundHttpException();
    }

    /**
     * "Move to a stage" as its own page (0.9.0): `edit` on a deal that is not in the trash, the stages of its funnel.
     */
    private function movePage(Request $request, string $id, PipelineDialogs $dialogs): Response
    {
        return $this->ui->guard(function () use ($request, $id, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->reader->deal($id);
            $this->ui->requireSubject(CrmPermission::Edit, SubjectRef::of(SubjectType::Deal, $deal->id, $deal->ownerId));
            $form = $this->ui->form(StageMoveType::class, ['stage' => $deal->stageId, 'version' => (string) $deal->version], ['stages' => $this->forms->stages($this->funnels->funnel($deal->funnelId)), 'reasons' => $this->forms->reasons()], 'crm_stage_move');
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($form->isSubmitted() && $form->isValid()) {
                $status = $dialogs->attempt($request, $form, function () use ($request, $form, $id, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $reason = $this->text($form, 'lost_reason');
                    $moved  = $this->deals->moveStage($id, $this->text($form, 'stage'), $reason === '' ? null : $reason, $this->text($form, 'lost_note'), $this->int($form, 'version'));
                    $this->ui->flash($request, 'success', $moved ? 'deal.moved' : 'deal.not_moved');

                    return $dialogs->done($request, 'nowo_crm_kit_deal_show', ['id' => $id]);
                });
                if ($status instanceof Response) {
                    return $status;
                }
            }

            return $dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_deal_move', ['id' => $id]), 'deal.move.title', 'deal.move.save', $this->ui->url('nowo_crm_kit_deal_show', ['id' => $id]), 'crm-deal-move', $status);
        });
    }

    /**
     * The trash / purge confirm of a deal as its own page (0.9.0): the question and a CSRF-protected POST. Trash needs
     * `delete` on a deal not in the trash, purge `manage` on a deal in the trash (the deal page's rules).
     */
    private function confirmPage(Request $request, string $id, string $what, PipelineDialogs $dialogs): Response
    {
        return $this->ui->guard(function () use ($request, $id, $what, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $purge = $what === 'purge';
            $deal  = $this->reader->deal($id, $purge);
            if ($purge && $deal->status !== DealStatus::Trashed) {
                throw new NotAvailableException();
            }
            $this->ui->requireSubject($purge ? CrmPermission::Manage : CrmPermission::Delete, SubjectRef::of(SubjectType::Deal, $deal->id, $deal->ownerId));
            $form = $this->ui->form(ActionType::class, null, [], 'crm_action');
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($form->isSubmitted() && $form->isValid()) {
                $status = $dialogs->attempt($request, $form, function () use ($request, $id, $purge, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $purge ? $this->deals->purge($id) : $this->deals->trash($id);
                    $this->ui->flash($request, 'success', $purge ? 'deal.purged' : 'deal.trashed');

                    return $dialogs->done($request, 'nowo_crm_kit_deals', $purge ? ['status' => 'trashed'] : []);
                });
                if ($status instanceof Response) {
                    return $status;
                }
            }

            return $dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_deal_' . $what, ['id' => $id]), 'deal.' . $what, 'deal.' . $what, $this->ui->url('nowo_crm_kit_deal_show', ['id' => $id]), 'crm-deal-' . $what, $status, intro: 'deal.' . $what . '_confirm', variant: 'btn_danger');
        });
    }

    /**
     * "Add a next action" as its own page (0.9.0): `edit` on the company, contact or deal.
     */
    private function nextActionPage(Request $request, SubjectType $subject, string $id, PipelineDialogs $dialogs): Response
    {
        return $this->ui->guard(function () use ($request, $subject, $id, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$route, $params] = $this->editable($subject, $id);
            $form             = $this->ui->form(NextActionType::class, ['dedupe' => PipelineForms::dedupe(), 'due_at' => new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC'))], ['owners' => $this->forms->owners()], 'crm_next_action');
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($form->isSubmitted() && $form->isValid()) {
                $status = $dialogs->attempt($request, $form, function () use ($request, $form, $subject, $id, $route, $params, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $due      = $form->get('due_at')->getData();
                    $assignee = $this->text($form, 'assignee');
                    if (!$due instanceof DateTimeImmutable) {
                        throw InvalidInputException::field('due_at', 'error.required');
                    }
                    try {
                        $this->actions->set($subject, $id, $this->text($form, 'title'), $due, $assignee === '' ? null : $assignee, $this->text($form, 'dedupe'), null, $this->ui->url($route, $params)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                    } catch (CrmTaskRefusedException) {
                        throw InvalidInputException::field('_', 'next_action.refused');
                    }
                    $this->ui->flash($request, 'success', 'next_action.added');

                    return $dialogs->done($request, $route, $params);
                });
                if ($status instanceof Response) {
                    return $status;
                }
            }

            return $dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_next_action_add', ['type' => $subject === SubjectType::Deal ? 'deals' : $subject->segment(), 'id' => $id]), 'next_action.add', 'next_action.save', $this->ui->url($route, $params), 'crm-next-action', $status);
        });
    }

    /**
     * "Set the lifecycle stage" as its own page (0.9.0): `edit` on the company or contact, the stages of the party
     * funnels of its type (404 without any).
     */
    private function lifecyclePage(Request $request, SubjectType $subject, string $id, PipelineDialogs $dialogs): Response
    {
        return $this->ui->guard(function () use ($request, $subject, $id, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$route, $params] = $this->editable($subject, $id);
            $choices          = [];
            foreach ($this->funnels->funnels(FunnelKind::Party) as $funnel) {
                if ($funnel->subjectType === $subject) {
                    foreach ($this->forms->stages($funnel) as $label => $stageId) {
                        $choices[$funnel->name . ' › ' . $label] = $funnel->id . '|' . $stageId;
                    }
                }
            }
            if ($choices === []) {
                throw new NotAvailableException();
            }
            $form = $this->ui->form(LifecycleType::class, null, ['stages' => $choices], 'crm_lifecycle');
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($form->isSubmitted() && $form->isValid()) {
                $status = $dialogs->attempt($request, $form, function () use ($request, $form, $subject, $id, $route, $params, $dialogs): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    [$funnelId, $stageId] = explode('|', $this->text($form, 'stage') . '|');
                    $this->lifecycle->set($subject, $id, $funnelId, $stageId); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                    $this->ui->flash($request, 'success', 'lifecycle.saved');

                    return $dialogs->done($request, $route, $params);
                });
                if ($status instanceof Response) {
                    return $status;
                }
            }

            return $dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_lifecycle', ['type' => $subject->segment(), 'id' => $id]), 'lifecycle.title', 'lifecycle.save', $this->ui->url($route, $params), 'crm-lifecycle', $status);
        });
    }

    /**
     * The page of a subject the actor may edit (the same checks as the POST: 404 outside the tenant or hidden, 403
     * without `edit`): its route and parameters. A company or contact that is not active (trashed, merged, erased)
     * answers 404 like a deal in the trash: its page never offers these forms and the write would be refused (ZAG-1).
     *
     * @return array{string, array<string, string>}
     */
    private function editable(SubjectType $subject, string $id): array
    {
        if ($subject === SubjectType::Deal) {
            $deal = $this->reader->deal($id);
            $this->ui->requireSubject(CrmPermission::Edit, SubjectRef::of(SubjectType::Deal, $deal->id, $deal->ownerId));

            return ['nowo_crm_kit_deal_show', ['id' => $id]];
        }
        $view = ($this->records ?? throw new NotFoundHttpException())->record($subject, $id);
        $this->ui->requireOn(CrmPermission::Edit, $view);
        if ($view->status !== RecordStatus::Active) {
            throw new NotAvailableException();
        }

        return ['nowo_crm_kit_show', ['type' => $subject->segment(), 'id' => $id]];
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
        $actions[] = new Action('custom', UiSupport::label('stats.title'), $this->ui->url('nowo_crm_kit_funnel_stats', ['id' => $funnel->id]), icon: UiIcons::STATS);

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
