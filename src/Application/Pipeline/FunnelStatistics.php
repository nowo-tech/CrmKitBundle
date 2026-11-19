<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\CrmExporter;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelStats;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageStats;

use function array_map;
use function array_values;
use function implode;
use function ksort;
use function round;

/**
 * Funnel statistics (0.3.0): per stage, the items in it now or at a past instant, the stale ones (now), and with
 * `amounts_view` the value and weighted value per currency (never summed across currencies). The same visibility as
 * the lists (visible owners in SQL), so a scoped person sees their own numbers. Counts need `view`, values need
 * `amounts_view` (owner QA-11). Optional: the flow (conversion from each stage to the next, the win rate from the
 * first stage, the average time spent in each stage) and the deals closed in a period with their lost reasons; the
 * CSV neutralises formula cells. Amounts at a past instant are today's amounts. Stateless.
 */
final readonly class FunnelStatistics
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private PipelineSupport $pipeline, private DealReader $deals)
    {
    }

    /**
     * A copy bound to `$caller` for one call (an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->pipeline->as($caller), $this->deals->as($caller));
    }

    /**
     * The statistics of a deal funnel. `$at` (a past instant) counts each deal in its stage at that time (no stale
     * count then); `$owner`, `$role`, `$tag` filter (role and tag through the deal's company or contact); `$flow`
     * adds conversion, win rate and time in stage; `$from` / `$to` add the deals closed in that period.
     *
     * @throws NotAvailableException no tenant, CRM off, unknown funnel
     * @throws AccessDeniedException `view`
     * @throws InvalidInputException a party funnel, `at` in the future, a period that ends before it starts
     */
    public function stats(string $funnelId, ?DateTimeImmutable $at = null, ?string $owner = null, ?string $role = null, ?string $tag = null, bool $flow = false, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): FunnelStats
    {
        $context = $this->pipeline->support->access->context(CrmPermission::View);
        $at      = $at?->setTimezone(new DateTimeZone('UTC'));
        if ($at instanceof DateTimeImmutable && $at > $context->now) {
            throw InvalidInputException::field('at', 'error.invalid');
        }
        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $to <= $from) {
            throw InvalidInputException::field('to', 'error.invalid');
        }
        $amounts = $this->allowed($context);

        return $this->pipeline->support->read(function () use ($context, $funnelId, $at, $owner, $role, $tag, $flow, $from, $to, $amounts): FunnelStats { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnel = $this->pipeline->funnel($context, $funnelId);
            PipelineSupport::kind($funnel, FunnelKind::Deal);
            $view    = PipelineSupport::view($funnel, $this->pipeline->stages($context, $funnelId));
            $owners  = $this->pipeline->support->access->visibleOwners($context);
            $filters = ['owner' => $owner, 'role' => $role, 'tag' => $tag];
            $rows    = $this->pipeline->store->stageNumbers($context->tenant, $funnelId, $owners, $filters, $at, $at instanceof DateTimeImmutable ? [] : $this->deals->cutoffs($context, $funnelId), $context->now);
            $numbers = [];
            foreach ($rows as $row) {
                $n = $numbers[$row['stage']] ?? ['count' => 0, 'stale' => 0, 'values' => []];
                $n['count'] += $row['count'];
                $n['stale'] += $row['stale'];
                if ($row['currency'] !== null) {
                    $n['values'][$row['currency']] = ['currency' => $row['currency'], 'sum' => Decimal::of($row['sum']) ?? '0', 'weighted' => Decimal::divide(Decimal::of($row['weighted']) ?? '0', 2)];
                }
                $numbers[$row['stage']] = $n;
            }
            $flowData = $flow ? $this->pipeline->store->stageFlow($context->tenant, $funnelId, $owners, $filters) : null;
            $stages   = [];
            foreach ($view->stages as $i => $stage) {
                $n      = $numbers[$stage->id] ?? ['count' => 0, 'stale' => 0, 'values' => []];
                $values = $n['values'];
                ksort($values);
                $next     = $view->stages[$i + 1] ?? null;
                $reached  = $flowData['reached'][$stage->id] ?? 0;
                $stages[] = new StageStats(
                    $stage->id,
                    $stage->name,
                    $stage->kind,
                    $stage->position,
                    $n['count'],
                    $at instanceof DateTimeImmutable ? null : $n['stale'],
                    $amounts ? array_values($values) : null,
                    $flowData !== null && $next !== null && $stage->kind === StageKind::Open && $reached > 0 ? round(($flowData['reached'][$next->id] ?? 0) / $reached, 4) : null,
                    $flowData['seconds'][$stage->id] ?? null,
                );
            }

            return new FunnelStats(
                $funnelId,
                $at ?? $context->now,
                $at instanceof DateTimeImmutable,
                $stages,
                !$amounts,
                $flowData === null ? null : $this->winRate($view, $flowData['reached']),
                $from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable ? $this->pipeline->store->closedIn($context->tenant, $funnelId, $owners, $filters, $from, $to) : null,
            );
        });
    }

    /**
     * The statistics as CSV: one line per stage and currency (formula cells neutralised; names are tenant text).
     *
     * @param array<string, string> $headers column => header (`stage`, `kind`, `count`, `stale`, `currency`, `value`, `weighted`)
     */
    public static function csv(FunnelStats $stats, array $headers): string
    {
        $columns = ['stage', 'kind', 'count', 'stale', 'currency', 'value', 'weighted'];
        $lines   = [CrmExporter::line(array_map(static fn (string $c): string => $headers[$c] ?? $c, $columns))];
        foreach ($stats->stages as $stage) {
            $base   = [$stage->name, $stage->kind->value, (string) $stage->count, $stage->stale === null ? '' : (string) $stage->stale];
            $values = $stage->values ?? [];
            if ($values === []) {
                $lines[] = CrmExporter::line([...$base, '', '', '']);

                continue;
            }
            foreach ($values as $value) {
                $lines[] = CrmExporter::line([...$base, $value['currency'], $value['sum'], $value['weighted']]);
            }
        }

        return implode('', $lines);
    }

    /**
     * The share of the items that reached the first stage and then a won stage.
     *
     * @param array<string, int> $reached
     */
    private function winRate(FunnelView $view, array $reached): ?float
    {
        $first = $view->stages[0] ?? null;
        $start = $first === null ? 0 : ($reached[$first->id] ?? 0);
        if ($start === 0) {
            return null;
        }
        $won = 0;
        foreach ($view->stages as $stage) {
            $won += $stage->kind === StageKind::Won ? ($reached[$stage->id] ?? 0) : 0;
        }

        return round($won / $start, 4);
    }

    private function allowed(CrmContext $context): bool
    {
        try {
            $this->pipeline->support->access->require($context, CrmPermission::AmountsView);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
