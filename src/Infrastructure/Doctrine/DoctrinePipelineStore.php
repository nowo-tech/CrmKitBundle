<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\PartyStage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcMicroDateTimeImmutableType;

use function array_map;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function method_exists;
use function str_replace;

/**
 * {@see PipelineStoreInterface} on the bundle's entity manager (0.3.0). Every query names the tenant, and every
 * result is re-checked with `===` against it (REQ-SEC-006 rule 2). The statistics are plain SQL with the same
 * visibility clause as the lists. Stateless: the entity manager comes from the runner on every call.
 *
 * @internal
 */
final readonly class DoctrinePipelineStore implements PipelineStoreInterface
{
    /**
     * Wires the runner.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function funnels(TenantRef $tenant, ?FunnelKind $kind = null, bool $withArchived = true): array
    {
        $qb = $this->em()->createQueryBuilder()->select('f')->from(Funnel::class, 'f')
            ->where('f.tenantId = :tenant')->setParameter('tenant', $tenant->value)->orderBy('f.position')->addOrderBy('f.id');
        if ($kind instanceof FunnelKind) {
            $qb->andWhere('f.kind = :kind')->setParameter('kind', $kind); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if (!$withArchived) {
            $qb->andWhere('f.archived = :archived')->setParameter('archived', false); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return $this->exact($qb->getQuery()->getResult(), Funnel::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function funnel(TenantRef $tenant, string $id, bool $lock = false): ?Funnel
    {
        return $this->one(Funnel::class, $tenant, $id, $lock);
    }

    /**
     * {@inheritDoc}
     */
    public function stages(TenantRef $tenant, array $funnelIds): array
    {
        if ($funnelIds === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('s')->from(Stage::class, 's')
            ->where('s.tenantId = :tenant')->andWhere('s.funnelId IN (:funnels)')
            ->setParameter('tenant', $tenant->value)->setParameter('funnels', $funnelIds, ArrayParameterType::STRING)
            ->orderBy('s.funnelId')->addOrderBy('s.position')->addOrderBy('s.id')->getQuery()->getResult();

        $kept = [];
        foreach ($this->exact($rows, Stage::class, $tenant) as $stage) {
            if (in_array($stage->getFunnelId(), $funnelIds, true)) {
                $kept[] = $stage;
            }
        }

        return $kept;
    }

    /**
     * {@inheritDoc}
     */
    public function stage(TenantRef $tenant, string $id): ?Stage
    {
        return $this->one(Stage::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function deal(TenantRef $tenant, string $id, bool $lock = false): ?Deal
    {
        return $this->one(Deal::class, $tenant, $id, $lock);
    }

    /**
     * {@inheritDoc}
     */
    public function deals(TenantRef $tenant, DealQuery $query, ?array $visibleOwners, ?array $after, int $limit, array $staleCutoffs, DateTimeImmutable $now): array
    {
        $qb = $this->em()->createQueryBuilder()->select('d')->from(Deal::class, 'd')
            ->where('d.tenantId = :tenant')->setParameter('tenant', $tenant->value);
        if ($query->status instanceof DealStatus) {
            $qb->andWhere('d.status = :status')->setParameter('status', $query->status); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        } else {
            $qb->andWhere('d.status <> :trashed')->setParameter('trashed', DealStatus::Trashed); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($query->ownerNone) {
            $qb->andWhere('d.ownerId IS NULL');
        } elseif ($query->ownerMe) {
            // An unresolved "Mine" (DealQuery::forActor() not called) matches nothing rather than everything.
            $qb->andWhere('1 = 0');
        }
        // 0.10.2: expected close day range (both ends included).
        if ($query->expectedCloseFrom instanceof DateTimeImmutable) {
            $qb->andWhere('d.expectedClose >= :closeFrom')->setParameter('closeFrom', new DateTimeImmutable($query->expectedCloseFrom->format('Y-m-d')), 'date_immutable'); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($query->expectedCloseTo instanceof DateTimeImmutable) {
            $qb->andWhere('d.expectedClose <= :closeTo')->setParameter('closeTo', new DateTimeImmutable($query->expectedCloseTo->format('Y-m-d')), 'date_immutable'); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        foreach (['funnelId' => $query->funnelId, 'ownerId' => $query->ownerNone || $query->ownerMe ? null : $query->ownerId, 'companyId' => $query->companyId, 'contactId' => $query->contactId] as $field => $value) {
            if ($value !== null) {
                $qb->andWhere('d.' . $field . ' = :' . $field)->setParameter($field, $value); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
        if ($query->stageIds !== null) {
            $qb->andWhere('d.stageId IN (:stages)')->setParameter('stages', $query->stageIds === [] ? [''] : $query->stageIds, ArrayParameterType::STRING); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        $text = $query->text ?? '';
        if ($text !== '') {
            $qb->andWhere("LOWER(d.title) LIKE LOWER(:q) ESCAPE '!'")->setParameter('q', '%' . $this->escapeLike($text) . '%'); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($query->staleOnly) {
            $qb->andWhere('d.status = :open')->andWhere($this->staleDql($qb, $staleCutoffs, $now))->setParameter('open', DealStatus::Open); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        $this->restrict($qb, 'd.ownerId', $visibleOwners);
        $this->keyset($qb, $query->sort, $after, $query->direction);

        $rows = $qb->setMaxResults($limit + 1)->getQuery()->getResult();
        $kept = [];
        foreach ($this->exact($rows, Deal::class, $tenant) as $deal) {
            if ($visibleOwners === null || $deal->getOwnerId() === null || in_array($deal->getOwnerId(), $visibleOwners, true)) {
                $kept[] = $deal;
            }
        }

        return $kept;
    }

    /**
     * {@inheritDoc}
     */
    public function dealsOf(TenantRef $tenant, SubjectType $type, string $id): array
    {
        $field = $type === SubjectType::Company ? 'companyId' : 'contactId';
        $rows  = $this->em()->createQueryBuilder()->select('d')->from(Deal::class, 'd')
            ->where('d.tenantId = :tenant')->andWhere('d.' . $field . ' = :id')
            ->setParameter('tenant', $tenant->value)->setParameter('id', $id)->orderBy('d.id')->getQuery()->getResult();

        return $this->exact($rows, Deal::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function countLiveDeals(TenantRef $tenant): int
    {
        return (int) $this->em()->createQueryBuilder()->select('COUNT(d.id)')->from(Deal::class, 'd')
            ->where('d.tenantId = :tenant')->andWhere('d.status <> :trashed')
            ->setParameter('tenant', $tenant->value)->setParameter('trashed', DealStatus::Trashed)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * {@inheritDoc}
     */
    public function stageChanges(TenantRef $tenant, SubjectType $type, string $itemId, int $limit): array
    {
        $rows = $this->em()->createQueryBuilder()->select('c')->from(StageChange::class, 'c')
            ->where('c.tenantId = :tenant')->andWhere('c.itemType = :type')->andWhere('c.itemId = :id')
            ->setParameter('tenant', $tenant->value)->setParameter('type', $type)->setParameter('id', $itemId)
            ->orderBy('c.occurredAt', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

        return $this->exact($rows, StageChange::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function partyStages(TenantRef $tenant, SubjectType $type, string $id): array
    {
        return $this->exact($this->em()->getRepository(PartyStage::class)->findBy(['tenantId' => $tenant->value, 'subjectType' => $type, 'subjectId' => $id], ['funnelId' => 'ASC']), PartyStage::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function partyStage(TenantRef $tenant, string $funnelId, string $subjectId): ?PartyStage
    {
        $row = $this->em()->getRepository(PartyStage::class)->findOneBy(['funnelId' => $funnelId, 'subjectId' => $subjectId]);

        return $row instanceof PartyStage && $row->getTenantId() === $tenant->value && $row->getFunnelId() === $funnelId && $row->getSubjectId() === $subjectId ? $row : null;
    }

    /**
     * {@inheritDoc}
     */
    public function nextActions(TenantRef $tenant, SubjectType $type, string $id, bool $openOnly, int $limit): array
    {
        $qb = $this->em()->createQueryBuilder()->select('n')->from(NextAction::class, 'n')
            ->where('n.tenantId = :tenant')->andWhere('n.subjectType = :type')->andWhere('n.subjectId = :id')
            ->setParameter('tenant', $tenant->value)->setParameter('type', $type)->setParameter('id', $id)
            ->addSelect('CASE WHEN n.status = :openStatus THEN 0 ELSE 1 END AS HIDDEN openFirst')->setParameter('openStatus', NextActionStatus::Open)
            ->orderBy('openFirst')->addOrderBy('n.dueAt')->addOrderBy('n.id')->setMaxResults($limit);
        if ($openOnly) {
            $qb->andWhere('n.status = :open')->setParameter('open', NextActionStatus::Open); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return $this->exact($qb->getQuery()->getResult(), NextAction::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function nextAction(TenantRef $tenant, string $id): ?NextAction
    {
        return $this->one(NextAction::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function nextActionByDedupe(TenantRef $tenant, string $dedupeKey): ?NextAction
    {
        $row = $this->em()->getRepository(NextAction::class)->findOneBy(['tenantId' => $tenant->value, 'dedupeKey' => $dedupeKey]);

        return $row instanceof NextAction && $row->getTenantId() === $tenant->value && $row->getDedupeKey() === $dedupeKey ? $row : null;
    }

    /**
     * {@inheritDoc}
     */
    public function earliestOpenDue(TenantRef $tenant, SubjectType $type, string $id): ?DateTimeImmutable
    {
        $first = $this->nextActions($tenant, $type, $id, true, 1)[0] ?? null;

        return $first?->getDueAt();
    }

    /**
     * {@inheritDoc}
     */
    public function trashedDealsBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): array
    {
        $rows = $this->em()->createQueryBuilder()->select('d')->from(Deal::class, 'd')
            ->where('d.tenantId = :tenant')->andWhere('d.status = :trashed')->andWhere('d.trashedAt < :before')
            ->setParameter('tenant', $tenant->value)->setParameter('trashed', DealStatus::Trashed)->setParameter('before', $before, UtcDateTimeImmutableType::NAME)
            ->orderBy('d.id')->setMaxResults($limit)->getQuery()->getResult();

        return array_map(static fn (Deal $d): string => $d->getId(), $this->exact($rows, Deal::class, $tenant));
    }

    /**
     * {@inheritDoc}
     */
    public function deleteItemRows(TenantRef $tenant, SubjectType $type, string $id): int
    {
        $deleted = 0;
        foreach ([[StageChange::class, 'item_type', 'item_id'], [PartyStage::class, 'subject_type', 'subject_id'], [NextAction::class, 'subject_type', 'subject_id']] as [$class, $typeColumn, $idColumn]) {
            $deleted += (int) $this->connection()->executeStatement(
                'DELETE FROM ' . $this->table($class) . ' WHERE tenant_id = ? AND ' . $typeColumn . ' = ? AND ' . $idColumn . ' = ?',
                [$tenant->value, $type->value, $id],
            );
        }

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function unlinkDeals(TenantRef $tenant, SubjectType $type, string $id): int
    {
        $column = $type === SubjectType::Company ? 'company_id' : 'contact_id';

        return (int) $this->connection()->executeStatement('UPDATE ' . $this->table(Deal::class) . ' SET ' . $column . ' = NULL, version = version + 1 WHERE tenant_id = ? AND ' . $column . ' = ?', [$tenant->value, $id]);
    }

    /**
     * {@inheritDoc}
     */
    public function repoint(TenantRef $tenant, SubjectType $type, string $fromId, string $toId): void
    {
        $c      = $this->connection();
        $column = $type === SubjectType::Company ? 'company_id' : 'contact_id';
        $c->executeStatement('UPDATE ' . $this->table(Deal::class) . ' SET ' . $column . ' = ?, version = version + 1 WHERE tenant_id = ? AND ' . $column . ' = ?', [$toId, $tenant->value, $fromId]);
        $c->executeStatement('UPDATE ' . $this->table(StageChange::class) . ' SET item_id = ? WHERE tenant_id = ? AND item_type = ? AND item_id = ?', [$toId, $tenant->value, $type->value, $fromId]);
        $c->executeStatement('UPDATE ' . $this->table(NextAction::class) . ' SET subject_id = ? WHERE tenant_id = ? AND subject_type = ? AND subject_id = ?', [$toId, $tenant->value, $type->value, $fromId]);
        $party = $this->table(PartyStage::class);
        // The survivor's own lifecycle stage wins in a funnel where both have one.
        $c->executeStatement('DELETE FROM ' . $party . ' WHERE tenant_id = ? AND subject_type = ? AND subject_id = ? AND funnel_id IN (SELECT funnel_id FROM (SELECT p2.funnel_id FROM ' . $party . ' p2 WHERE p2.tenant_id = ? AND p2.subject_id = ?) k)', [$tenant->value, $type->value, $fromId, $tenant->value, $toId]);
        $c->executeStatement('UPDATE ' . $party . ' SET subject_id = ? WHERE tenant_id = ? AND subject_type = ? AND subject_id = ?', [$toId, $tenant->value, $type->value, $fromId]);
    }

    /**
     * {@inheritDoc}
     */
    public function stageNumbers(TenantRef $tenant, string $funnelId, ?array $visibleOwners, array $filters, ?DateTimeImmutable $at, array $staleCutoffs, DateTimeImmutable $now): array
    {
        $params = [];
        $types  = [];
        $deals  = $this->table(Deal::class);
        if ($at instanceof DateTimeImmutable) {
            // The stage of each item at `$at`: its last stage change at or before then (items created later left out).
            $changes = $this->table(StageChange::class);
            $from    = $changes . ' sc INNER JOIN ' . $deals . ' d ON d.id = sc.item_id AND d.tenant_id = sc.tenant_id';
            $stage   = 'sc.to_stage_id';
            $where   = ['sc.tenant_id = ?', 'sc.funnel_id = ?', "sc.item_type = 'deal'", 'sc.occurred_at <= ?', 'd.created_at <= ?', "d.status <> 'trashed'",
                'sc.occurred_at = (SELECT MAX(sc2.occurred_at) FROM ' . $changes . ' sc2 WHERE sc2.tenant_id = sc.tenant_id AND sc2.item_type = sc.item_type AND sc2.item_id = sc.item_id AND sc2.funnel_id = sc.funnel_id AND sc2.occurred_at <= ?)'];
            $params = [$tenant->value, $funnelId, $at, $at, $at];
            $types  = [ParameterType::STRING, ParameterType::STRING, UtcMicroDateTimeImmutableType::NAME, UtcDateTimeImmutableType::NAME, UtcMicroDateTimeImmutableType::NAME];
            $stale  = '0';
        } else {
            $from                                  = $deals . ' d';
            $stage                                 = 'd.stage_id';
            $where                                 = ['d.tenant_id = ?', 'd.funnel_id = ?', "d.status <> 'trashed'"];
            $params                                = [$tenant->value, $funnelId];
            $types                                 = [ParameterType::STRING, ParameterType::STRING];
            [$staleSql, $staleParams, $staleTypes] = $this->staleSql($staleCutoffs, $now);
            $stale                                 = "CASE WHEN d.status = 'open' AND (" . $staleSql . ') THEN 1 ELSE 0 END';
            // The CASE comes first in the SELECT list: its parameters lead.
            $params = [...$staleParams, ...$params];
            $types  = [...$staleTypes, ...$types];
        }
        [$filterSql, $filterParams, $filterTypes] = $this->dealFilters($tenant, $visibleOwners, $filters);
        $sql                                      = 'SELECT ' . $stage . ' AS stage_id, d.currency AS currency, COUNT(*) AS n, SUM(d.amount) AS total, '
            . 'SUM(d.amount * COALESCE(d.probability, s.probability)) AS weighted, SUM(' . $stale . ') AS stale '
            . 'FROM ' . $from . ' INNER JOIN ' . $this->table(Stage::class) . ' s ON s.id = ' . $stage . ' AND s.tenant_id = d.tenant_id '
            . 'WHERE ' . implode(' AND ', [...$where, ...$filterSql]) . ' GROUP BY ' . $stage . ', d.currency';
        $out = [];
        foreach ($this->connection()->fetchAllAssociative($sql, [...$params, ...$filterParams], [...$types, ...$filterTypes]) as $row) {
            $out[] = [
                'stage'    => is_string($row['stage_id']) ? $row['stage_id'] : '',
                'currency' => is_string($row['currency']) ? $row['currency'] : null,
                'count'    => is_numeric($row['n']) ? (int) $row['n'] : 0,
                'sum'      => is_numeric($row['total']) ? (string) $row['total'] : null,
                'weighted' => is_numeric($row['weighted']) ? (string) $row['weighted'] : null,
                'stale'    => is_numeric($row['stale']) ? (int) $row['stale'] : 0,
            ];
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function stageFlow(TenantRef $tenant, string $funnelId, ?array $visibleOwners, array $filters): array
    {
        [$filterSql, $filterParams, $filterTypes] = $this->dealFilters($tenant, $visibleOwners, $filters);
        $base                                     = $this->table(StageChange::class) . ' sc INNER JOIN ' . $this->table(Deal::class) . ' d ON d.id = sc.item_id AND d.tenant_id = sc.tenant_id';
        $where                                    = implode(' AND ', ['sc.tenant_id = ?', 'sc.funnel_id = ?', "sc.item_type = 'deal'", "d.status <> 'trashed'", ...$filterSql]);
        $params                                   = [$tenant->value, $funnelId, ...$filterParams];
        $types                                    = [ParameterType::STRING, ParameterType::STRING, ...$filterTypes];
        $reached                                  = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT sc.to_stage_id AS stage_id, COUNT(DISTINCT sc.item_id) AS n FROM ' . $base . ' WHERE ' . $where . ' GROUP BY sc.to_stage_id', $params, $types) as $row) {
            if (is_string($row['stage_id']) && is_numeric($row['n'])) {
                $reached[$row['stage_id']] = (int) $row['n'];
            }
        }
        $seconds = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT sc.from_stage_id AS stage_id, AVG(sc.seconds_in_from) AS s FROM ' . $base . ' WHERE ' . $where . ' AND sc.from_stage_id IS NOT NULL AND sc.seconds_in_from IS NOT NULL GROUP BY sc.from_stage_id', $params, $types) as $row) {
            if (is_string($row['stage_id']) && is_numeric($row['s'])) {
                $seconds[$row['stage_id']] = (int) $row['s'];
            }
        }

        return ['reached' => $reached, 'seconds' => $seconds];
    }

    /**
     * {@inheritDoc}
     */
    public function closedIn(TenantRef $tenant, string $funnelId, ?array $visibleOwners, array $filters, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$filterSql, $filterParams, $filterTypes] = $this->dealFilters($tenant, $visibleOwners, $filters);
        $rows                                     = $this->connection()->fetchAllAssociative(
            'SELECT d.status AS status, d.lost_reason AS reason, COUNT(*) AS n FROM ' . $this->table(Deal::class) . ' d WHERE '
            . implode(' AND ', ['d.tenant_id = ?', 'd.funnel_id = ?', "d.status IN ('won', 'lost')", 'd.closed_at >= ?', 'd.closed_at < ?', ...$filterSql])
            . ' GROUP BY d.status, d.lost_reason',
            [$tenant->value, $funnelId, $from, $to, ...$filterParams],
            [ParameterType::STRING, ParameterType::STRING, UtcDateTimeImmutableType::NAME, UtcDateTimeImmutableType::NAME, ...$filterTypes],
        );
        $out = ['won' => 0, 'lost' => 0, 'reasons' => []];
        foreach ($rows as $row) {
            $n = is_numeric($row['n']) ? (int) $row['n'] : 0;
            if ($row['status'] === 'won') {
                $out['won'] += $n;
            } else {
                $out['lost'] += $n;
                $reason                  = is_string($row['reason']) ? $row['reason'] : '';
                $out['reasons'][$reason] = ($out['reasons'][$reason] ?? 0) + $n;
            }
        }

        return $out;
    }

    /**
     * The visibility, owner, role and tag clauses on the deal alias `d` (SQL).
     *
     * @param list<string>|null $visibleOwners
     * @param array{owner?: string|null, role?: string|null, tag?: string|null} $filters
     *
     * @return array{list<string>, list<mixed>, list<ArrayParameterType|ParameterType|string>}
     */
    private function dealFilters(TenantRef $tenant, ?array $visibleOwners, array $filters): array
    {
        $sql    = [];
        $params = [];
        $types  = [];
        if ($visibleOwners !== null) {
            $this->boundOwners($visibleOwners);
            if ($visibleOwners === []) {
                $sql[] = 'd.owner_id IS NULL';
            } else {
                $sql[]    = '(d.owner_id IS NULL OR d.owner_id IN (?))';
                $params[] = $visibleOwners;
                $types[]  = ArrayParameterType::STRING;
            }
        }
        if (($filters['owner'] ?? null) !== null) {
            $sql[]    = 'd.owner_id = ?';
            $params[] = $filters['owner'];
            $types[]  = ParameterType::STRING;
        }
        foreach (['role' => [RoleAssignment::class, 'role_key'], 'tag' => [Tagging::class, 'tag_id']] as $key => [$class, $column]) {
            $value = $filters[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $sql[] = 'EXISTS (SELECT 1 FROM ' . $this->table($class) . ' x WHERE x.tenant_id = ? AND x.' . $column . ' = ? AND ('
                . "(x.subject_type = 'company' AND x.subject_id = d.company_id) OR (x.subject_type = 'contact' AND x.subject_id = d.contact_id)))";
            $params = [...$params, $tenant->value, $value];
            $types  = [...$types, ParameterType::STRING, ParameterType::STRING];
        }

        return [$sql, $params, $types];
    }

    /**
     * The stale condition of an open deal in SQL: entered its stage before the stage's cutoff, or an overdue open next
     * action.
     *
     * @param array<string, DateTimeImmutable> $staleCutoffs
     *
     * @return array{string, list<mixed>, list<ParameterType|string>}
     */
    private function staleSql(array $staleCutoffs, DateTimeImmutable $now): array
    {
        $parts  = [];
        $params = [];
        $types  = [];
        foreach ($staleCutoffs as $stageId => $cutoff) {
            $parts[] = '(d.stage_id = ? AND d.stage_entered_at < ?)';
            $params  = [...$params, (string) $stageId, $cutoff];
            $types   = [...$types, ParameterType::STRING, UtcMicroDateTimeImmutableType::NAME];
        }
        $parts[]  = 'd.next_action_at < ?';
        $params[] = $now;
        $types[]  = UtcDateTimeImmutableType::NAME;

        return [implode(' OR ', $parts), $params, $types];
    }

    /**
     * The stale condition in DQL (parameters bound on the builder).
     *
     * @param array<string, DateTimeImmutable> $staleCutoffs
     */
    private function staleDql(QueryBuilder $qb, array $staleCutoffs, DateTimeImmutable $now): string
    {
        $parts = [];
        $i     = 0;
        foreach ($staleCutoffs as $stageId => $cutoff) {
            $parts[] = '(d.stageId = :staleStage' . $i . ' AND d.stageEnteredAt < :staleCut' . $i . ')';
            $qb->setParameter('staleStage' . $i, (string) $stageId)->setParameter('staleCut' . $i, $cutoff, UtcMicroDateTimeImmutableType::NAME); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            ++$i;
        }
        $parts[] = 'd.nextActionAt < :staleNow';
        $qb->setParameter('staleNow', $now, UtcDateTimeImmutableType::NAME); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * The order and the keyset condition of a deal list. 0.10.2: every order in both directions (`title` too); rows
     * without an expected close or an amount stay last, in id order, whatever the direction.
     *
     * @param array{v: string|null, id: string}|null $after
     */
    private function keyset(QueryBuilder $qb, string $sort, ?array $after, string $direction = 'desc'): void
    {
        $dir = $direction === 'asc' ? 'ASC' : 'DESC';
        $op  = $dir === 'ASC' ? '>' : '<';
        if ($sort === DealQuery::SORT_UPDATED || $sort === DealQuery::SORT_TITLE) {
            $field = $sort === DealQuery::SORT_TITLE ? 'd.title' : 'd.updatedAt';
            $qb->orderBy($field, $dir)->addOrderBy('d.id', $dir); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            if ($after !== null && $after['v'] !== null) {
                $qb->andWhere($field . ' ' . $op . ' :kv OR (' . $field . ' = :kv AND d.id ' . $op . ' :kid)')->setParameter('kid', $after['id']); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                if ($sort === DealQuery::SORT_TITLE) {
                    $qb->setParameter('kv', $after['v']); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                } else {
                    $qb->setParameter('kv', new DateTimeImmutable($after['v'], new DateTimeZone('UTC')), UtcMicroDateTimeImmutableType::NAME); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
            }

            return;
        }
        // Expected close or amount: rows without a value come last, in id order.
        $field = $sort === DealQuery::SORT_AMOUNT ? 'd.amount' : 'd.expectedClose';
        $qb->addSelect('CASE WHEN ' . $field . ' IS NULL THEN 1 ELSE 0 END AS HIDDEN nullsLast') // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            ->orderBy('nullsLast')->addOrderBy($field, $dir)->addOrderBy('d.id', 'ASC');
        if ($after === null) {
            return;
        }
        if ($after['v'] === null) {
            $qb->andWhere($field . ' IS NULL AND d.id > :kid')->setParameter('kid', $after['id']); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

            return;
        }
        $qb->andWhere($field . ' ' . $op . ' :kv OR (' . $field . ' = :kv AND d.id > :kid) OR ' . $field . ' IS NULL') // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            ->setParameter('kid', $after['id']);
        if ($sort === DealQuery::SORT_AMOUNT) {
            $qb->setParameter('kv', $after['v']); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        } else {
            $qb->setParameter('kv', new DateTimeImmutable($after['v'], new DateTimeZone('UTC')), 'date_immutable'); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
    }

    /**
     * Resource scoping in DQL: only ownerless rows and the visible owners'.
     *
     * @param list<string>|null $visibleOwners
     */
    private function restrict(QueryBuilder $qb, string $field, ?array $visibleOwners): void
    {
        if ($visibleOwners === null) {
            return;
        }
        $this->boundOwners($visibleOwners);
        if ($visibleOwners === []) {
            $qb->andWhere($field . ' IS NULL'); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

            return;
        }
        $qb->andWhere($field . ' IS NULL OR ' . $field . ' IN (:visibleOwners)')->setParameter('visibleOwners', $visibleOwners, ArrayParameterType::STRING); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Refuses more visible owners than one SQL statement may bind.
     *
     * @param list<string> $owners
     */
    private function boundOwners(array $owners): void
    {
        if (count($owners) > DoctrineCrmStore::MAX_VISIBLE_OWNERS) {
            throw new LogicException('nowo_crm_kit: a nowo_crm_kit.record_visibility listener made more than ' . DoctrineCrmStore::MAX_VISIBLE_OWNERS . ' owners visible. Call RecordVisibilityEvent::allowAll() for such a person and decide single records with nowo_crm_kit.record_access_check listeners instead.');
        }
    }

    /**
     * One tenant row by id, optionally locked `FOR UPDATE` (refreshed).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function one(string $class, TenantRef $tenant, string $id, bool $lock = false): ?object
    {
        if ($lock) {
            $rows = $this->em()->createQueryBuilder()->select('x')->from($class, 'x')->where('x.id = :id')->andWhere('x.tenantId = :tenant')
                ->setParameter('id', $id)->setParameter('tenant', $tenant->value)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getResult();
            $row = $this->exact($rows, $class, $tenant)[0] ?? null;
        } else {
            $row = $this->em()->find($class, $id);
        }
        if (!$row instanceof $class || !method_exists($row, 'getTenantId') || !method_exists($row, 'getId') || $row->getTenantId() !== $tenant->value || $row->getId() !== $id) {
            return null;
        }

        return $row;
    }

    /**
     * The rows of the class whose tenant matches byte for byte.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function exact(mixed $rows, string $class, TenantRef $tenant): array
    {
        $kept = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($row instanceof $class && method_exists($row, 'getTenantId') && $row->getTenantId() === $tenant->value) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * A LIKE operand with `!` as the escape character.
     */
    private function escapeLike(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }

    /**
     * The (prefixed) table of an entity.
     *
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return $this->em()->getClassMetadata($class)->getTableName();
    }

    private function em(): EntityManagerInterface
    {
        return $this->runner->manager();
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}
