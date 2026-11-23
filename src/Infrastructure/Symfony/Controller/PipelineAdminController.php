<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use DateInterval;
use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelStatistics;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelStats;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FunnelType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\StageType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\StatsFilterType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function max;

/**
 * Funnel administration (0.3.0, `manage`: funnels, stages, their order) and the statistics page of a deal funnel
 * (`view`; values with `amounts_view`) with its CSV. Stateless.
 */
final readonly class PipelineAdminController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private FunnelReader $reader,
        private FunnelService $funnels,
        private FunnelStatistics $statistics,
        private PipelineForms $forms,
        private CrmAccess $access,
    ) {
    }

    /**
     * The funnels and the create form.
     */
    #[AsWriteUnit]
    public function funnels(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context(CrmPermission::Manage);
            $form = $this->ui->form(FunnelType::class, ['kind' => 'deal'], [], 'crm_funnel');
            $form->handleRequest($request);
            $status = Response::HTTP_OK;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    try {
                        $kind = $this->text($form, 'kind');
                        $id   = $this->funnels->create(
                            $kind === 'deal' ? FunnelKind::Deal : FunnelKind::Party,
                            $kind === 'party_company' ? SubjectType::Company : ($kind === 'party_contact' ? SubjectType::Contact : null),
                            $this->text($form, 'name'),
                            ['open' => $this->ui->trans('stage.default.open'), 'won' => $this->ui->trans('stage.default.won'), 'lost' => $this->ui->trans('stage.default.lost'), 'first' => $this->ui->trans('stage.default.first')],
                        );
                        $this->ui->flash($request, 'success', 'funnel.created');

                        return $this->ui->redirect('nowo_crm_kit_funnel_edit', ['id' => $id]);
                    } catch (InvalidInputException $e) {
                        $this->ui->errors($form, $e);
                    }
                }
            }
            $rows = array_map(fn (FunnelView $f): array => ['id' => $f->id, 'name' => $f->name, 'kind' => $this->ui->trans('funnel.kind.' . ($f->kind === FunnelKind::Deal ? 'deal' : 'party_' . $f->subjectType?->value)), 'stages' => (string) count($f->stages), 'archived' => $f->archived ? $this->ui->trans('value.yes') : ''], $this->reader->funnels(null, true));

            return $this->ui->render('@NowoCrmKit/pipeline/funnels.html.twig', [
                'page' => new ListPage(
                    new PageHeader(UiSupport::label('funnels.title'), UiSupport::label('funnels.intro'), [], tabs: $this->ui->tabs('funnels')),
                    [new Column('name', UiSupport::label('funnel.form.name'), required: true), new Column('kind', UiSupport::label('funnel.form.kind')), new Column('stages', UiSupport::label('funnel.stages'), priority: 2), new Column('archived', UiSupport::label('funnel.form.archived'), priority: 3)],
                    $rows,
                    rowActions: fn (mixed $row): array => is_array($row) && is_string($row['id'] ?? null) ? [Action::edit($this->ui->url('nowo_crm_kit_funnel_edit', ['id' => $row['id']]), UiSupport::label('funnel.edit'))] : [],
                    empty: new EmptyState(UiSupport::label('funnels.empty')),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-funnels'],
                    responsive: 'cards',
                ),
                'form' => $form->createView(),
            ], $status);
        });
    }

    /**
     * One funnel: rename / archive, its stages (edit, archive, order) and the add-stage form.
     */
    #[AsWriteUnit]
    public function funnel(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->access->context(CrmPermission::Manage);
            $funnel = $this->reader->funnel($id);
            $form   = $this->ui->form(FunnelType::class, ['name' => $funnel->name, 'archived' => $funnel->archived, 'version' => (string) $funnel->version], ['versioned' => true], 'crm_funnel');
            $add    = $this->ui->form(StageType::class, ['probability' => 50, 'kind' => 'open'], $funnel->kind === FunnelKind::Party ? ['kinds' => ['stage.kind.open' => 'open']] : [], 'crm_stage');
            $status = Response::HTTP_OK;
            try {
                $form->handleRequest($request);
                if ($form->isSubmitted()) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    if ($form->isValid()) {
                        $this->funnels->change($id, $this->text($form, 'name'), $form->get('archived')->getData() === true, $this->int($form, 'version'));
                        $this->ui->flash($request, 'success', 'funnel.saved');

                        return $this->ui->redirect('nowo_crm_kit_funnel_edit', ['id' => $id]);
                    }
                }
                $add->handleRequest($request);
                if ($add->isSubmitted()) {
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                    if ($add->isValid()) {
                        $this->funnels->addStage($id, $this->text($add, 'name'), StageKind::from($this->text($add, 'kind')), $this->int($add, 'probability'), $this->nullableInt($add, 'rot_days')); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                        $this->ui->flash($request, 'success', 'stage.created');

                        return $this->ui->redirect('nowo_crm_kit_funnel_edit', ['id' => $id]);
                    }
                }
            } catch (InvalidInputException $e) {
                $this->ui->errors($add->isSubmitted() ? $add : $form, $e);
            } catch (ConflictException $e) {
                $this->ui->flash($request, 'error', 'flash.conflict.' . $e->errorCode);
                $status = Response::HTTP_CONFLICT;
            }
            $token  = $this->ui->actionToken();
            $stages = [];
            foreach ($funnel->stages as $stage) {
                $stages[] = [
                    'stage' => $stage,
                    'form'  => $this->ui->form(StageType::class, ['name' => $stage->name, 'probability' => $stage->probability, 'rot_days' => $stage->rotDays, 'archived' => $stage->archived, 'version' => (string) $stage->version], ['versioned' => true], 'crm_stage')->createView(),
                    'up'    => $this->move($token, $stage->id, 'up', $funnel->id),
                    'down'  => $this->move($token, $stage->id, 'down', $funnel->id),
                ];
            }

            return $this->ui->render('@NowoCrmKit/pipeline/funnel.html.twig', [
                'page' => new FormPage(
                    new PageHeader($funnel->name, UiSupport::label('funnel.kind.' . ($funnel->kind === FunnelKind::Deal ? 'deal' : 'party_' . $funnel->subjectType?->value)), [], tabs: $this->ui->tabs('funnels')),
                    $form->createView(),
                    UiSupport::label('form.save'),
                    $this->ui->url('nowo_crm_kit_funnels'),
                    UiSupport::label('form.cancel'),
                    bundle: UiSupport::DOMAIN,
                    layout: $this->ui->layout(),
                    attributes: ['data-testid' => 'crm-funnel'],
                ),
                'funnel' => $funnel,
                'stages' => $stages,
                'add'    => $add->createView(),
            ], $status);
        });
    }

    /**
     * Changes a stage (POST).
     */
    #[AsWriteUnit]
    public function stage(Request $request, string $id): Response
    {
        $funnel = (string) $request->query->get('funnel', '');
        $back   = $this->ui->redirect('nowo_crm_kit_funnel_edit', ['id' => $funnel === '' ? '00000000-0000-0000-0000-000000000000' : $funnel]);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->ui->form(StageType::class, null, ['versioned' => true], 'crm_stage');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw InvalidInputException::field('_', 'error.stage_invalid');
            }
            $this->funnels->changeStage($id, $this->text($form, 'name'), $this->int($form, 'probability'), $this->nullableInt($form, 'rot_days'), $form->get('archived')->getData() === true, $this->int($form, 'version'));
            $this->ui->flash($request, 'success', 'stage.saved');

            return $back;
        }, $back);
    }

    /**
     * Moves a stage one place up or down (POST).
     */
    #[AsWriteUnit]
    public function moveStage(Request $request, string $id, string $direction): Response
    {
        $funnel = (string) $request->query->get('funnel', '');
        $back   = $this->ui->redirect('nowo_crm_kit_funnel_edit', ['id' => $funnel === '' ? '00000000-0000-0000-0000-000000000000' : $funnel]);

        return $this->ui->act($request, function () use ($request, $id, $direction, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $form = $this->ui->form(ActionType::class, null, [], 'crm_action');
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw InvalidInputException::field('_', 'error.csrf');
            }
            $this->funnels->moveStage($id, $direction === 'up' ? -1 : 1);

            return $back;
        }, $back);
    }

    /**
     * The statistics of a deal funnel: per stage count, stale, value and weighted value per currency, conversion,
     * average time in stage, win rate; the closed deals of a period; filters by past instant, owner, role, tag.
     */
    public function stats(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$funnel, $stats, $filters] = $this->compute($request, $id);
            $max                        = 1;
            foreach ($stats->stages as $stage) {
                $max = max($max, $stage->count);
            }

            return $this->ui->render('@NowoCrmKit/pipeline/stats.html.twig', [
                'page'    => new ShowPage(new PageHeader(UiSupport::label('stats.title_of', ['%name%' => $funnel->name]), null, [new Action('export', UiSupport::label('list.export'), $this->ui->url('nowo_crm_kit_funnel_stats_csv', ['id' => $id] + $request->query->all()), icon: 'download'), new Action('custom', UiSupport::label('board.title'), $this->ui->url('nowo_crm_kit_deal_board', ['id' => $id]), icon: UiIcons::BOARD)], tabs: $this->ui->tabs('deals')), bundle: UiSupport::DOMAIN, layout: $this->ui->layout(), attributes: ['data-testid' => 'crm-stats']),
                'funnel'  => $funnel,
                'stats'   => $stats,
                'max'     => $max,
                'filters' => $filters->createView(),
            ]);
        });
    }

    /**
     * The statistics as CSV (formula cells neutralised).
     */
    public function statsCsv(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [, $stats] = $this->compute($request, $id);
            $headers   = [];
            foreach (['stage', 'kind', 'count', 'stale', 'currency', 'value', 'weighted'] as $column) {
                $headers[$column] = $this->ui->trans('stats.csv.' . $column);
            }

            return new Response(FunnelStatistics::csv($stats, $headers), Response::HTTP_OK, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="funnel-stats.csv"',
                'Cache-Control'       => 'no-store, private',
            ]);
        });
    }

    /**
     * The funnel, its statistics and the filter form of a request.
     *
     * @return array{FunnelView, FunnelStats, FormInterface<mixed>}
     */
    private function compute(Request $request, string $id): array
    {
        $funnel = $this->reader->funnel($id);
        if ($funnel->kind !== FunnelKind::Deal) {
            throw new NotAvailableException();
        }
        $filters = $this->ui->form(StatsFilterType::class, null, ['owners' => $this->forms->owners(), 'roles' => $this->forms->roles(), 'tags' => $this->forms->tags()], '');
        $filters->handleRequest($request);
        $valid = $filters->isSubmitted() && $filters->isValid();
        $at    = $valid ? $filters->get('at')->getData() : null;
        $from  = $valid ? $filters->get('from')->getData() : null;
        $to    = $valid ? $filters->get('to')->getData() : null;
        $role  = $valid && $filters->has('role') ? $this->text($filters, 'role') : '';
        try {
            $stats = $this->statistics->stats(
                $id,
                $at instanceof DateTimeImmutable ? $at : null,
                $valid && $this->text($filters, 'owner') !== '' ? $this->text($filters, 'owner') : null,
                $role === '' ? null : $role,
                $valid && $this->text($filters, 'tag') !== '' ? $this->text($filters, 'tag') : null,
                true,
                $from instanceof DateTimeImmutable ? $from : null,
                $to instanceof DateTimeImmutable ? $to->add(new DateInterval('P1D')) : null, // @igor-ignore - an immutable date of this request, never a shared service
            );
        } catch (InvalidInputException $e) {
            $this->ui->errors($filters, $e);
            $stats = $this->statistics->stats($id, null, null, null, null, true);
        }

        return [$funnel, $stats, $filters];
    }

    private function move(string $token, string $stageId, string $direction, string $funnelId): Action
    {
        return new Action('move', UiSupport::label('stage.move_' . $direction), $this->ui->url('nowo_crm_kit_stage_move', ['id' => $stageId, 'direction' => $direction, 'funnel' => $funnelId]), method: 'POST', csrfToken: $token, icon: $direction === 'up' ? UiIcons::STAGE_UP : UiIcons::STAGE_DOWN, csrfField: 'crm_action[_token]');
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
    private function int(FormInterface $form, string $field): int
    {
        return $this->nullableInt($form, $field) ?? 0;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function nullableInt(FormInterface $form, string $field): ?int
    {
        $value = $form->has($field) ? $form->get($field)->getData() : null;

        return is_numeric($value) ? (int) $value : null;
    }
}
