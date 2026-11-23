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
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Port\ExternalRefHooksInterface;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotConnectionStatus;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Counter;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcMicroDateTimeImmutableType;

use function in_array;
use function intdiv;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * {@see HubSpotStoreInterface} and {@see ExternalRefHooksInterface} on the bundle's entity manager (0.10.0, only while
 * `import.hubspot.enabled`). Every query names the tenant and every result is re-checked with `===` against it and
 * the requested keys (REQ-SEC-006 rule 2; the key columns are binary NO PAD on MySQL / MariaDB). The token-check
 * counter lives in `crm_counter` (two rows per tenant, locked in a fixed order). Stateless: the entity manager comes
 * from the runner on every call.
 *
 * @internal
 */
final readonly class DoctrineHubSpotStore implements HubSpotStoreInterface, ExternalRefHooksInterface
{
    /** Counter row: token checks in the current window. */
    public const ATTEMPTS = 'hubspot_connect';

    /** Counter row: start of the current window (Unix minutes). */
    public const WINDOW = 'hubspot_connect_at';

    /** Reasons a new token clears. */
    private const TOKEN_REASONS = ['hubspot_token_invalid', 'hubspot_scope_missing'];

    /**
     * Wires the runner.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function connection(TenantRef $tenant): ?HubSpotConnection
    {
        return $this->exactConnection($this->em()->getRepository(HubSpotConnection::class)->findOneBy(['tenantId' => $tenant->value]), $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function lockConnection(TenantRef $tenant): ?HubSpotConnection
    {
        $rows = $this->em()->createQueryBuilder()->select('c')->from(HubSpotConnection::class, 'c')
            ->where('c.tenantId = :tenant')->setParameter('tenant', $tenant->value)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getResult();
        foreach (is_array($rows) ? $rows : [] as $row) {
            $exact = $this->exactConnection($row, $tenant);
            if ($exact instanceof HubSpotConnection) {
                return $exact;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function connections(?string $afterId, int $limit): array
    {
        // Audit ZAM-10: one batch at a time (LIMIT in SQL).
        $rows = $this->db()->createQueryBuilder()->select('id', 'tenant_id')->from($this->table(HubSpotConnection::class))
            ->where('id > :after')->orderBy('id')->setParameter('after', $afterId ?? '')->setMaxResults($limit)
            ->executeQuery()->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            if (is_string($row['id']) && is_string($row['tenant_id'])) {
                $out[] = ['tenant' => $row['tenant_id'], 'id' => $row['id']];
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function takeConnectAttempt(TenantRef $tenant, DateTimeImmutable $now, int $limit, int $interval): bool
    {
        $minute = intdiv($now->getTimestamp(), 60);
        // Always the window row first, then the attempts row: one order, so two checks never deadlock.
        $start = $this->lockedCounter($tenant, self::WINDOW);
        $count = $this->lockedCounter($tenant, self::ATTEMPTS);
        if ($start === null || $minute - $start >= intdiv($interval + 59, 60)) {
            $this->setCounter($tenant, self::WINDOW, $minute);
            $this->setCounter($tenant, self::ATTEMPTS, 1);

            return true;
        }
        if ($count >= $limit) {
            return false;
        }
        $this->setCounter($tenant, self::ATTEMPTS, ($count ?? 0) + 1);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function hasOpenImport(TenantRef $tenant): bool
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT tenant_id FROM ' . $this->table(Import::class) . ' WHERE tenant_id = ? AND format = ? AND status IN (?, ?)',
            [$tenant->value, ImportFormat::HubSpot->value, ImportStatus::Preview->value, ImportStatus::Running->value],
        );
        foreach ($rows as $row) {
            if ($row['tenant_id'] === $tenant->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function refs(TenantRef $tenant, string $accountId, string $objectType, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('r')->from(ExternalRef::class, 'r')
            ->where('r.tenantId = :tenant')->andWhere('r.source = :source')->andWhere('r.accountId = :account')
            ->andWhere('r.objectType = :type')->andWhere('r.externalId IN (:ids)')
            ->setParameter('tenant', $tenant->value)->setParameter('source', ExternalRef::HUBSPOT)->setParameter('account', $accountId)
            ->setParameter('type', $objectType)->setParameter('ids', $externalIds, ArrayParameterType::STRING)
            ->getQuery()->getResult();
        $refs = [];
        foreach (is_array($rows) ? $rows : [] as $ref) {
            if ($ref instanceof ExternalRef && $ref->getTenantId() === $tenant->value && $ref->getSource() === ExternalRef::HUBSPOT && $ref->getAccountId() === $accountId && $ref->getObjectType() === $objectType && in_array($ref->getExternalId(), $externalIds, true)) {
                $refs[$ref->getExternalId()] = $ref;
            }
        }

        return $refs;
    }

    /**
     * {@inheritDoc}
     */
    public function retarget(ExternalRef $ref, string $targetId, DateTimeImmutable $now): void
    {
        $ref->retarget($targetId, $now);
    }

    /**
     * {@inheritDoc}
     */
    public function lockCursor(string $importId, string $token): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT status, claim_token, run_cursor, rows_done FROM ' . $this->table(Import::class) . ' WHERE id = ?' . $this->forUpdate(), [$importId]);
        if ($row === false || $row['status'] !== ImportStatus::Running->value || $row['claim_token'] !== $token) {
            return null;
        }

        return ['cursor' => is_string($row['run_cursor']) ? $row['run_cursor'] : null, 'rows' => is_numeric($row['rows_done']) ? (int) $row['rows_done'] : 0];
    }

    /**
     * {@inheritDoc}
     */
    public function confirmedBy(string $importId, string $actor): void
    {
        $this->db()->executeStatement('UPDATE ' . $this->table(Import::class) . ' SET confirmed_by_id = ? WHERE id = ?', [$actor, $importId]);
    }

    /**
     * {@inheritDoc}
     */
    public function saveCursor(string $importId, string $cursorJson): void
    {
        $this->db()->executeStatement('UPDATE ' . $this->table(Import::class) . ' SET run_cursor = ? WHERE id = ?', [$cursorJson, $importId]);
    }

    /**
     * {@inheritDoc}
     */
    public function resumeForConnection(TenantRef $tenant, string $connectionId, DateTimeImmutable $now): int
    {
        return (int) $this->db()->executeStatement(
            'UPDATE ' . $this->table(Import::class) . ' SET resume_after = ?, error_code = NULL, version = version + 1 WHERE tenant_id = ? AND connection_id = ? AND status = ? AND claim_token IS NULL AND error_code IN (?)',
            [$now, $tenant->value, $connectionId, ImportStatus::Running->value, self::TOKEN_REASONS],
            [0 => UtcDateTimeImmutableType::NAME, 4 => ArrayParameterType::STRING],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function invalidate(TenantRef $tenant, string $connectionId, string $usedSealed, DateTimeImmutable $now): void
    {
        $this->db()->executeStatement(
            'UPDATE ' . $this->table(HubSpotConnection::class) . ' SET status = ?, updated_at = ?, version = version + 1 WHERE id = ? AND tenant_id = ? AND token_sealed = ?',
            [HubSpotConnectionStatus::Invalid->value, $now, $connectionId, $tenant->value, $usedSealed],
            [1 => UtcMicroDateTimeImmutableType::NAME],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function move(TenantRef $tenant, SubjectType $type, string $fromId, string $toId): void
    {
        $this->db()->executeStatement(
            'UPDATE ' . $this->table(ExternalRef::class) . ' SET target_id = ? WHERE tenant_id = ? AND target_type = ? AND target_id = ?',
            [$toId, $tenant->value, $type->value, $fromId],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function forget(TenantRef $tenant, SubjectType $type, string $id): void
    {
        $this->db()->executeStatement(
            'DELETE FROM ' . $this->table(ExternalRef::class) . ' WHERE tenant_id = ? AND target_type = ? AND target_id = ? AND erased = ?',
            [$tenant->value, $type->value, $id, false],
            [3 => ParameterType::BOOLEAN],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function tombstone(TenantRef $tenant, string $contactId): void
    {
        $this->db()->executeStatement(
            'UPDATE ' . $this->table(ExternalRef::class) . ' SET erased = ? WHERE tenant_id = ? AND target_type = ? AND target_id = ?',
            [true, $tenant->value, SubjectType::Contact->value, $contactId],
            [0 => ParameterType::BOOLEAN],
        );
    }

    /**
     * The tenant's connection when it is one (exact tenant).
     */
    private function exactConnection(mixed $row, TenantRef $tenant): ?HubSpotConnection
    {
        return $row instanceof HubSpotConnection && $row->getTenantId() === $tenant->value ? $row : null;
    }

    /**
     * A counter row's value, locked `FOR UPDATE` (inserted when missing; null for a new row).
     */
    private function lockedCounter(TenantRef $tenant, string $resource): ?int
    {
        $sql   = 'SELECT value FROM ' . $this->table(Counter::class) . ' WHERE tenant_id = ? AND resource = ?' . $this->forUpdate();
        $value = $this->db()->fetchOne($sql, [$tenant->value, $resource]);
        if (is_numeric($value)) {
            return (int) $value;
        }
        $platform = $this->db()->getDatabasePlatform();
        $table    = $this->table(Counter::class);
        $insert   = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, 0)',
            $platform instanceof PostgreSQLPlatform    => 'INSERT INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, 0) ON CONFLICT DO NOTHING',
            default                                    => 'INSERT OR IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, 0)',
        };
        $this->db()->executeStatement($insert, [$tenant->value, $resource]);
        $this->db()->fetchOne($sql, [$tenant->value, $resource]);

        return null;
    }

    private function setCounter(TenantRef $tenant, string $resource, int $value): void
    {
        $this->db()->executeStatement('UPDATE ' . $this->table(Counter::class) . ' SET value = ? WHERE tenant_id = ? AND resource = ?', [$value, $tenant->value, $resource], [0 => ParameterType::INTEGER]);
    }

    /**
     * The row-lock clause of the platform (none on SQLite: one writer at a time).
     */
    private function forUpdate(): string
    {
        return $this->db()->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
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

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }
}
