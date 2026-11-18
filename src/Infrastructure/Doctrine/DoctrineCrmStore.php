<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\Counter;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\ImportRow;
use Nowo\CrmKitBundle\Domain\Model\MergeRecord;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strtolower;
use function method_exists;
use function preg_replace;
use function sort;
use function str_replace;
use function strtoupper;
use function trim;

use const SORT_STRING;

/**
 * {@see CrmStoreInterface} on the bundle's entity manager (contract sections 6 and 15). Every query names the tenant,
 * and every result is re-checked with `===` against it (REQ-SEC-006 rule 2); counters, claims and settles are atomic
 * conditional UPDATEs. Stateless: the entity manager comes from the runner on every call.
 *
 * @internal
 */
final readonly class DoctrineCrmStore implements CrmStoreInterface
{
    /** Pseudo-tenant of the bundle's own claim rows in `crm_counter` (never a host tenant: the `__` prefix). */
    public const SYSTEM_TENANT = '__crm__';

    /** Resource of the retention purge's single-run claim (value = unix time of the claim, 0 = free). */
    public const PURGE_CLAIM = '__purge__';

    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function add(object $entity): void
    {
        $this->em()->persist($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(object $entity): void
    {
        $this->em()->remove($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        $this->em()->flush();
    }

    /**
     * {@inheritDoc}
     */
    public function record(SubjectType $type, TenantRef $tenant, string $id): ?CrmRecord
    {
        $record = $this->em()->find($this->class($type), $id);

        return $record instanceof CrmRecord && $record->getTenantId() === $tenant->value && $record->getId() === $id ? $record : null;
    }

    /**
     * {@inheritDoc}
     */
    public function lockRecords(SubjectType $type, TenantRef $tenant, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $this->em()->createQueryBuilder()->select('r')->from($this->class($type), 'r')
            ->where('r.id IN (:ids)')->andWhere('r.tenantId = :tenant')->orderBy('r.id')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)->setParameter('tenant', $tenant->value)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);

        return $this->byId($this->exact($query->getResult(), $this->class($type), $tenant), $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function records(SubjectType $type, TenantRef $tenant, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('r')->from($this->class($type), 'r')
            ->where('r.id IN (:ids)')->andWhere('r.tenantId = :tenant')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)->setParameter('tenant', $tenant->value)
            ->getQuery()->getResult();

        return $this->byId($this->exact($rows, $this->class($type), $tenant), $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function contactByEmailKey(TenantRef $tenant, string $emailKey): ?Contact
    {
        $contact = $this->em()->getRepository(Contact::class)->findOneBy(['tenantId' => $tenant->value, 'emailKey' => $emailKey]);

        return $contact instanceof Contact && $contact->getTenantId() === $tenant->value && $contact->getEmailKey() === $emailKey ? $contact : null;
    }

    /**
     * {@inheritDoc}
     */
    public function list(TenantRef $tenant, ListQuery $query): array
    {
        $qb    = $this->filtered($tenant, $query);
        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $field = match ($query->sort) {
            'created' => 'r.createdAt',
            'updated' => 'r.updatedAt',
            default   => $query->type === SubjectType::Company ? 'r.nameKey' : 'r.sortKey',
        };
        $rows = $qb->orderBy($field, $query->direction)->addOrderBy('r.id', $query->direction) // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            ->setFirstResult(($query->page - 1) * $query->perPage)->setMaxResults($query->perPage)
            ->getQuery()->getResult();

        return [$this->exact($rows, $this->class($query->type), $tenant, $query->ownerId), $total];
    }

    /**
     * {@inheritDoc}
     */
    public function batch(TenantRef $tenant, ListQuery $query, ?string $afterId, int $limit): array
    {
        $qb = $this->filtered($tenant, $query)->orderBy('r.id', 'ASC')->setMaxResults($limit);
        if ($afterId !== null) {
            $qb->andWhere('r.id > :after')->setParameter('after', $afterId); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return $this->exact($qb->getQuery()->getResult(), $this->class($query->type), $tenant, $query->ownerId);
    }

    /**
     * {@inheritDoc}
     */
    public function duplicates(CrmRecord $record, int $limit = 10): array
    {
        $tenant = new TenantRef($record->getTenantId());
        $qb     = $this->em()->createQueryBuilder()->select('r')->from($record::class, 'r')
            ->where('r.tenantId = :tenant')->andWhere('r.status = :active')->andWhere('r.id <> :id')
            ->setParameter('tenant', $tenant->value)->setParameter('active', RecordStatus::Active)->setParameter('id', $record->getId())
            ->setMaxResults($limit)->orderBy('r.id');
        $or = $qb->expr()->orX();
        if ($record instanceof Company) {
            $or->add('r.nameKey = :name');
            $qb->setParameter('name', $record->getNameKey());
            if ($record->getDomainKey() !== null) {
                $or->add('r.domainKey = :domain');
                $qb->setParameter('domain', $record->getDomainKey());
            }
            if ($record->getTaxIdKey() !== null) {
                $or->add('r.taxIdKey = :tax');
                $qb->setParameter('tax', $record->getTaxIdKey());
            }
        } elseif ($record instanceof Contact) {
            $or->add('r.sortKey = :sort');
            $qb->setParameter('sort', $record->getSortKey());
        }
        $qb->andWhere($or);

        return array_values(array_filter($this->exact($qb->getQuery()->getResult(), CrmRecord::class, $tenant), static function (CrmRecord $other) use ($record): bool {
            return ($record instanceof Company && $other instanceof Company && ($other->getNameKey() === $record->getNameKey() || ($record->getDomainKey() !== null && $other->getDomainKey() === $record->getDomainKey()) || ($record->getTaxIdKey() !== null && $other->getTaxIdKey() === $record->getTaxIdKey())))
                || ($record instanceof Contact && $other instanceof Contact && $other->getSortKey() === $record->getSortKey());
        }));
    }

    /**
     * {@inheritDoc}
     */
    public function search(SubjectType $type, TenantRef $tenant, string $text, int $limit, ?string $companyId = null): array
    {
        return $this->list($tenant, new ListQuery($type, $text, perPage: $limit, companyId: $companyId));
    }

    /**
     * {@inheritDoc}
     */
    public function affiliations(TenantRef $tenant, SubjectType $side, string $id): array
    {
        $rows = $this->em()->createQueryBuilder()->select('a')->from(Affiliation::class, 'a')
            ->where('a.tenantId = :tenant')->andWhere($side === SubjectType::Contact ? 'a.contactId = :id' : 'a.companyId = :id')
            ->setParameter('tenant', $tenant->value)->setParameter('id', $id)
            ->orderBy('a.primary', 'DESC')->addOrderBy('a.createdAt')->addOrderBy('a.id')->getQuery()->getResult();

        return array_values(array_filter($this->exact($rows, Affiliation::class, $tenant), static fn (Affiliation $a): bool => ($side === SubjectType::Contact ? $a->getContactId() : $a->getCompanyId()) === $id));
    }

    /**
     * {@inheritDoc}
     */
    public function affiliation(TenantRef $tenant, string $id): ?Affiliation
    {
        return $this->one(Affiliation::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function tags(TenantRef $tenant): array
    {
        return $this->exact($this->em()->getRepository(Tag::class)->findBy(['tenantId' => $tenant->value], ['nameKey' => 'ASC', 'id' => 'ASC']), Tag::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function tag(TenantRef $tenant, string $id): ?Tag
    {
        return $this->one(Tag::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function taggings(TenantRef $tenant, SubjectType $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('t')->from(Tagging::class, 't')
            ->where('t.tenantId = :tenant')->andWhere('t.subjectType = :type')->andWhere('t.subjectId IN (:ids)')
            ->setParameter('tenant', $tenant->value)->setParameter('type', $type)->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->orderBy('t.id')->getQuery()->getResult();

        return $this->exact($rows, Tagging::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function taggingsOfTag(TenantRef $tenant, string $tagId): array
    {
        return array_values(array_filter($this->exact($this->em()->getRepository(Tagging::class)->findBy(['tenantId' => $tenant->value, 'tagId' => $tagId]), Tagging::class, $tenant), static fn (Tagging $t): bool => $t->getTagId() === $tagId));
    }

    /**
     * {@inheritDoc}
     */
    public function fields(TenantRef $tenant, SubjectType $type, bool $withArchived): array
    {
        $criteria = ['tenantId' => $tenant->value, 'subjectType' => $type];
        if (!$withArchived) {
            $criteria['archived'] = false;
        }

        return $this->exact($this->em()->getRepository(FieldDefinition::class)->findBy($criteria, ['position' => 'ASC', 'fieldKey' => 'ASC']), FieldDefinition::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function field(TenantRef $tenant, string $id): ?FieldDefinition
    {
        return $this->one(FieldDefinition::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function notes(TenantRef $tenant, SubjectType $type, string $id): array
    {
        return $this->exact($this->em()->getRepository(Note::class)->findBy(['tenantId' => $tenant->value, 'subjectType' => $type, 'subjectId' => $id], ['pinned' => 'DESC', 'createdAt' => 'DESC', 'id' => 'DESC']), Note::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function note(TenantRef $tenant, string $id): ?Note
    {
        return $this->one(Note::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function activities(TenantRef $tenant, array $subjects, int $limit, ?string $beforeId): array
    {
        if ($subjects === []) {
            return [];
        }
        $qb = $this->em()->createQueryBuilder()->select('a')->from(Activity::class, 'a')
            ->where('a.tenantId = :tenant')->setParameter('tenant', $tenant->value)
            ->orderBy('a.id', 'DESC')->setMaxResults($limit);
        $byType = [];
        foreach ($subjects as [$type, $id]) {
            $byType[$type->value][] = $id;
        }
        $or = $qb->expr()->orX();
        foreach ($byType as $type => $ids) {
            $or->add('(a.subjectType = :t_' . $type . ' AND a.subjectId IN (:ids_' . $type . '))');
            $qb->setParameter('t_' . $type, SubjectType::from($type))->setParameter('ids_' . $type, $ids, ArrayParameterType::STRING);
        }
        $qb->andWhere($or);
        if ($beforeId !== null) {
            $qb->andWhere('a.id < :before')->setParameter('before', $beforeId);
        }

        return $this->exact($qb->getQuery()->getResult(), Activity::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function allActivities(TenantRef $tenant, SubjectType $type, string $id): array
    {
        return $this->exact($this->em()->getRepository(Activity::class)->findBy(['tenantId' => $tenant->value, 'subjectType' => $type, 'subjectId' => $id], ['id' => 'ASC']), Activity::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function consents(TenantRef $tenant, string $contactId): array
    {
        return $this->exact($this->em()->getRepository(Consent::class)->findBy(['tenantId' => $tenant->value, 'contactId' => $contactId], ['recordedAt' => 'DESC', 'id' => 'DESC']), Consent::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function links(TenantRef $tenant, SubjectType $type, string $id): array
    {
        return $this->exact($this->em()->getRepository(HostLink::class)->findBy(['tenantId' => $tenant->value, 'subjectType' => $type, 'subjectId' => $id], ['hostType' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC']), HostLink::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function link(TenantRef $tenant, string $id): ?HostLink
    {
        return $this->one(HostLink::class, $tenant, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function linksTo(TenantRef $tenant, HostObjectRef $host): array
    {
        $rows = $this->em()->getRepository(HostLink::class)->findBy(['tenantId' => $tenant->value, 'hostType' => $host->type, 'hostId' => $host->id], ['id' => 'ASC']);

        return array_values(array_filter($this->exact($rows, HostLink::class, $tenant), static fn (HostLink $l): bool => $l->getHostType() === $host->type && $l->getHostId() === $host->id));
    }

    /**
     * {@inheritDoc}
     */
    public function mergesOf(TenantRef $tenant, string $mergedId): array
    {
        $rows = $this->em()->createQueryBuilder()->select('m')->from(MergeRecord::class, 'm')
            ->where('m.tenantId = :tenant')->andWhere('m.mergedId = :id OR m.survivorId = :id')
            ->setParameter('tenant', $tenant->value)->setParameter('id', $mergedId)->orderBy('m.id')->getQuery()->getResult();

        return $this->exact($rows, MergeRecord::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function settings(TenantRef $tenant): ?CrmSettings
    {
        $settings = $this->em()->find(CrmSettings::class, $tenant->value);

        return $settings instanceof CrmSettings && $settings->getTenantId() === $tenant->value ? $settings : null;
    }

    /**
     * {@inheritDoc}
     */
    public function import(TenantRef $tenant, string $id): ?Import
    {
        $import = $this->one(Import::class, $tenant, $id);
        if ($import instanceof Import) {
            $this->em()->refresh($import);
        }

        return $import;
    }

    /**
     * {@inheritDoc}
     */
    public function importRows(string $importId, int $limit, bool $pendingOnly): array
    {
        $criteria = ['importId' => $importId];
        if ($pendingOnly) {
            $criteria['outcome'] = RowOutcome::Pending;
        }
        $rows = $this->em()->getRepository(ImportRow::class)->findBy($criteria, ['rowNo' => 'ASC'], $limit);

        return array_values(array_filter($rows, static fn (ImportRow $r): bool => $r->getImportId() === $importId));
    }

    /**
     * {@inheritDoc}
     */
    public function claimImport(TenantRef $tenant, string $id, int $version, string $token, string $mappingJson, string $policy, DateTimeImmutable $now): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(Import::class) . ' SET status = ?, claim_token = ?, claimed_at = ?, mapping = ?, duplicate_policy = ?, version = version + 1 WHERE id = ? AND tenant_id = ? AND status = ? AND version = ?',
            [ImportStatus::Running->value, $token, $now, $mappingJson, $policy, $id, $tenant->value, ImportStatus::Preview->value, $version],
            [2 => UtcDateTimeImmutableType::NAME, 8 => ParameterType::INTEGER],
        ) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function takeOverImport(TenantRef $tenant, string $id, string $token, DateTimeImmutable $now, DateTimeImmutable $staleBefore): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(Import::class) . ' SET claim_token = ?, claimed_at = ?, version = version + 1 WHERE id = ? AND tenant_id = ? AND status = ? AND claimed_at < ?',
            [$token, $now, $id, $tenant->value, ImportStatus::Running->value, $staleBefore],
            [1 => UtcDateTimeImmutableType::NAME, 5 => UtcDateTimeImmutableType::NAME],
        ) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function holdsImport(string $id, string $token): bool
    {
        $row = $this->connection()->fetchAssociative('SELECT status, claim_token FROM ' . $this->table(Import::class) . ' WHERE id = ?' . $this->forUpdate(), [$id]);

        return $row !== false && $row['status'] === ImportStatus::Running->value && $row['claim_token'] === $token;
    }

    /**
     * {@inheritDoc}
     */
    public function settleRow(string $rowId, RowOutcome $outcome, ?string $recordId, ?string $message): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(ImportRow::class) . ' SET outcome = ?, record_id = ?, message = ? WHERE id = ? AND outcome = ?',
            [$outcome->value, $recordId, $message, $rowId, RowOutcome::Pending->value],
        ) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function advanceImport(string $id, int $rows, array $counts, DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'UPDATE ' . $this->table(Import::class) . ' SET rows_done = rows_done + ?, created_count = created_count + ?, updated_count = updated_count + ?, duplicate_count = duplicate_count + ?, invalid_count = invalid_count + ?, limit_count = limit_count + ?, claimed_at = ? WHERE id = ?',
            [$rows, $counts['created'] ?? 0, $counts['updated'] ?? 0, $counts['duplicate'] ?? 0, $counts['invalid'] ?? 0, $counts['limit'] ?? 0, $now, $id],
            [0 => ParameterType::INTEGER, 1 => ParameterType::INTEGER, 2 => ParameterType::INTEGER, 3 => ParameterType::INTEGER, 4 => ParameterType::INTEGER, 5 => ParameterType::INTEGER, 6 => UtcDateTimeImmutableType::NAME],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function finishImport(string $id, ImportStatus $status, DateTimeImmutable $now, ?ImportStatus $from = null): bool
    {
        $sql    = 'UPDATE ' . $this->table(Import::class) . ' SET status = ?, finished_at = ?, claim_token = NULL, version = version + 1 WHERE id = ?';
        $params = [$status->value, $now, $id];
        if ($from instanceof ImportStatus) {
            $sql .= ' AND status = ?';
            $params[] = $from->value;
        }

        return $this->connection()->executeStatement($sql, $params, [1 => UtcDateTimeImmutableType::NAME]) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function staleImports(DateTimeImmutable $before, int $limit): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT tenant_id, id FROM ' . $this->table(Import::class) . ' WHERE status = ? AND claimed_at < ? ORDER BY id',
            [ImportStatus::Running->value, $before],
            [1 => UtcDateTimeImmutableType::NAME],
        );
        $stale = [];
        foreach ($rows as $row) {
            if (count($stale) < $limit && is_string($row['tenant_id']) && is_string($row['id'])) {
                $stale[] = ['tenant' => $row['tenant_id'], 'id' => $row['id']];
            }
        }

        return $stale;
    }

    /**
     * {@inheritDoc}
     */
    public function takeCounter(TenantRef $tenant, string $resource, ?int $limit, callable $current): bool
    {
        $table = $this->table(Counter::class);
        $sql   = 'UPDATE ' . $table . ' SET value = value + 1 WHERE tenant_id = ? AND resource = ?' . ($limit === null ? '' : ' AND value < ?');
        $args  = $limit === null ? [$tenant->value, $resource] : [$tenant->value, $resource, $limit];
        if ($this->connection()->executeStatement($sql, $args) === 1) {
            return true;
        }
        if ($this->counterExists($tenant, $resource)) {
            return false;
        }
        $this->insertCounter($tenant, $resource, $current());

        return $this->connection()->executeStatement($sql, $args) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function releaseCounter(TenantRef $tenant, string $resource): void
    {
        $this->connection()->executeStatement('UPDATE ' . $this->table(Counter::class) . ' SET value = value - 1 WHERE tenant_id = ? AND resource = ? AND value > 0', [$tenant->value, $resource]);
    }

    /**
     * {@inheritDoc}
     */
    public function lockCounter(TenantRef $tenant, string $resource): void
    {
        $this->insertCounter($tenant, $resource, 0);
        $this->connection()->fetchOne('SELECT value FROM ' . $this->table(Counter::class) . ' WHERE tenant_id = ? AND resource = ?' . $this->forUpdate(), [$tenant->value, $resource]);
    }

    /**
     * {@inheritDoc}
     */
    public function claimPurge(int $now, int $staleBefore): bool
    {
        $tenant = new TenantRef(self::SYSTEM_TENANT);
        $this->insertCounter($tenant, self::PURGE_CLAIM, 0);

        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(Counter::class) . ' SET value = ? WHERE tenant_id = ? AND resource = ? AND value < ?',
            [$now, self::SYSTEM_TENANT, self::PURGE_CLAIM, $staleBefore],
            [0 => ParameterType::INTEGER, 3 => ParameterType::INTEGER],
        ) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function releasePurge(): void
    {
        $this->connection()->executeStatement('UPDATE ' . $this->table(Counter::class) . ' SET value = 0 WHERE tenant_id = ? AND resource = ?', [self::SYSTEM_TENANT, self::PURGE_CLAIM]);
    }

    /**
     * {@inheritDoc}
     */
    public function setCounter(TenantRef $tenant, string $resource, int $value): void
    {
        $this->connection()->executeStatement('UPDATE ' . $this->table(Counter::class) . ' SET value = ? WHERE tenant_id = ? AND resource = ?', [$value, $tenant->value, $resource], [0 => ParameterType::INTEGER]);
    }

    /**
     * {@inheritDoc}
     */
    public function countActive(TenantRef $tenant, SubjectType $type): int
    {
        return (int) $this->em()->createQueryBuilder()->select('COUNT(r.id)')->from($this->class($type), 'r')
            ->where('r.tenantId = :tenant')->andWhere('r.status = :active')
            ->setParameter('tenant', $tenant->value)->setParameter('active', RecordStatus::Active)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * {@inheritDoc}
     */
    public function tenants(): array
    {
        $tenants = [];
        foreach ([Company::class, Contact::class, CrmSettings::class] as $class) {
            foreach ($this->connection()->fetchFirstColumn('SELECT DISTINCT tenant_id FROM ' . $this->table($class)) as $tenant) {
                if (is_string($tenant)) {
                    $tenants[$tenant] = true;
                }
            }
        }
        $list = array_keys($tenants);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * {@inheritDoc}
     */
    public function trashedBefore(TenantRef $tenant, SubjectType $type, DateTimeImmutable $before, int $limit): array
    {
        $rows = $this->em()->createQueryBuilder()->select('r')->from($this->class($type), 'r')
            ->where('r.tenantId = :tenant')->andWhere('r.status = :trashed')->andWhere('r.trashedAt < :before')
            ->setParameter('tenant', $tenant->value)->setParameter('trashed', RecordStatus::Trashed)->setParameter('before', $before, UtcDateTimeImmutableType::NAME)
            ->orderBy('r.id')->setMaxResults($limit)->getQuery()->getResult();

        return array_map(static fn (CrmRecord $r): string => $r->getId(), $this->exact($rows, $this->class($type), $tenant));
    }

    /**
     * {@inheritDoc}
     */
    public function deleteActivitiesBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int
    {
        return $this->deleteBatch(Activity::class, $tenant, 'occurred_at < ?', [$before], [UtcDateTimeImmutableType::NAME], $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteImportRowsBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int
    {
        $imports = $this->table(Import::class);

        return $this->deleteBatch(ImportRow::class, $tenant, 'import_id IN (SELECT i.id FROM ' . $imports . ' i WHERE i.tenant_id = ? AND i.finished_at IS NOT NULL AND i.finished_at < ?)', [$tenant->value, $before], [ParameterType::STRING, UtcDateTimeImmutableType::NAME], $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMergesBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int
    {
        return $this->deleteBatch(MergeRecord::class, $tenant, 'merged_at < ?', [$before], [UtcDateTimeImmutableType::NAME], $limit);
    }

    /**
     * Selects a batch of ids by keyset, keeps the exact-tenant rows, deletes them by primary key.
     *
     * @param class-string $class
     * @param list<mixed> $params
     * @param list<ParameterType|string> $types
     */
    private function deleteBatch(string $class, TenantRef $tenant, string $condition, array $params, array $types, int $limit): int
    {
        $table = $this->table($class);
        $rows  = $this->connection()->fetchAllAssociative(
            'SELECT id, tenant_id FROM ' . $table . ' WHERE tenant_id = ? AND ' . $condition . ' ORDER BY id',
            [$tenant->value, ...$params],
            [ParameterType::STRING, ...$types],
        );
        $ids = [];
        foreach ($rows as $row) {
            if ($row['tenant_id'] === $tenant->value && is_string($row['id']) && count($ids) < $limit) {
                $ids[] = $row['id'];
            }
        }
        if ($ids === []) {
            return 0;
        }
        $this->connection()->executeStatement('DELETE FROM ' . $table . ' WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);

        return count($ids);
    }

    /**
     * The list query with the query's filters (no order, no page).
     */
    private function filtered(TenantRef $tenant, ListQuery $query): QueryBuilder
    {
        $qb = $this->em()->createQueryBuilder()->select('r')->from($this->class($query->type), 'r')
            ->where('r.tenantId = :tenant')->andWhere('r.status = :status')
            ->setParameter('tenant', $tenant->value)->setParameter('status', $query->status);
        $text = $query->text === null ? '' : trim($query->text);
        if ($text !== '') {
            $like  = '%' . $this->escapeLike(mb_strtolower($text, 'UTF-8')) . '%';
            $upper = '%' . $this->escapeLike(strtoupper((string) preg_replace('/[\s.\-\/]+/u', '', $text))) . '%';
            $qb->setParameter('q', $like);
            if ($query->type === SubjectType::Company) {
                $qb->andWhere("r.nameKey LIKE :q ESCAPE '!' OR LOWER(r.legalName) LIKE :q ESCAPE '!' OR r.domainKey LIKE :q ESCAPE '!' OR LOWER(r.email) LIKE :q ESCAPE '!' OR r.taxIdKey LIKE :upper ESCAPE '!'")->setParameter('upper', $upper);
            } else {
                $qb->andWhere("r.sortKey LIKE :q ESCAPE '!' OR LOWER(r.displayName) LIKE :q ESCAPE '!' OR r.emailKey LIKE :q ESCAPE '!' OR r.phone LIKE :q ESCAPE '!' OR r.mobile LIKE :q ESCAPE '!'");
            }
        }
        if ($query->tagId !== null) {
            $qb->andWhere('EXISTS (SELECT 1 FROM ' . Tagging::class . ' tg WHERE tg.subjectId = r.id AND tg.subjectType = :tagType AND tg.tagId = :tag AND tg.tenantId = :tenant)')
                ->setParameter('tagType', $query->type)->setParameter('tag', $query->tagId);
        }
        if ($query->ownerId !== null) {
            $qb->andWhere('r.ownerId = :owner')->setParameter('owner', $query->ownerId);
        }
        if ($query->companyId !== null && $query->type === SubjectType::Contact) {
            $qb->andWhere('EXISTS (SELECT 1 FROM ' . Affiliation::class . ' af WHERE af.contactId = r.id AND af.companyId = :company AND af.tenantId = :tenant)')
                ->setParameter('company', $query->companyId);
        }

        return $qb;
    }

    /**
     * The rows of the class whose tenant (and owner, when filtered) match byte for byte.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function exact(mixed $rows, string $class, TenantRef $tenant, ?string $owner = null): array
    {
        $kept = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!$row instanceof $class || !method_exists($row, 'getTenantId') || $row->getTenantId() !== $tenant->value) {
                continue;
            }
            if ($owner !== null && (!$row instanceof CrmRecord || $row->getOwnerId() !== $owner)) {
                continue;
            }
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * Records keyed by id, only the requested ones.
     *
     * @param list<CrmRecord> $records
     * @param list<string> $ids
     *
     * @return array<string, CrmRecord>
     */
    private function byId(array $records, array $ids): array
    {
        $map = [];
        foreach ($records as $record) {
            if (in_array($record->getId(), $ids, true)) {
                $map[$record->getId()] = $record;
            }
        }

        return $map;
    }

    /**
     * One tenant row by id.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function one(string $class, TenantRef $tenant, string $id): ?object
    {
        $row = $this->em()->find($class, $id);
        if (!$row instanceof $class || !method_exists($row, 'getTenantId') || !method_exists($row, 'getId') || $row->getTenantId() !== $tenant->value || $row->getId() !== $id) {
            return null;
        }

        return $row;
    }

    private function counterExists(TenantRef $tenant, string $resource): bool
    {
        $value = $this->connection()->fetchOne('SELECT value FROM ' . $this->table(Counter::class) . ' WHERE tenant_id = ? AND resource = ?', [$tenant->value, $resource]);

        return is_numeric($value);
    }

    /**
     * Inserts a counter row unless it exists (no failed statement, so a PostgreSQL transaction stays usable).
     */
    private function insertCounter(TenantRef $tenant, string $resource, int $value): void
    {
        $platform = $this->connection()->getDatabasePlatform();
        $table    = $this->table(Counter::class);
        $sql      = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, ?)',
            $platform instanceof PostgreSQLPlatform    => 'INSERT INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            default                                    => 'INSERT OR IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, ?)',
        };
        $this->connection()->executeStatement($sql, [$tenant->value, $resource, $value], [2 => ParameterType::INTEGER]);
    }

    /**
     * A LIKE operand with `!` as the escape character (`%`, `_` and `!` are literal).
     */
    private function escapeLike(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }

    private function forUpdate(): string
    {
        return $this->connection()->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
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

    /**
     * The entity class of a type.
     *
     * @return class-string<CrmRecord>
     */
    private function class(SubjectType $type): string
    {
        return $type === SubjectType::Company ? Company::class : Contact::class;
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
