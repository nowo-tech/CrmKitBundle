<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\CrmKitBundle\Domain\Model\Counter;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function intdiv;
use function is_numeric;

/**
 * A fixed-window rate limit on two `crm_counter` rows (the window's start minute and the count), run inside the
 * caller's write unit under the rows' locks (window row first, then count row: one order, so two checks never
 * deadlock). The HubSpot checks (0.10.0) and the mention endpoints (0.11.0) share it. Stateless.
 *
 * @internal
 */
final readonly class DoctrineCounterWindow
{
    /**
     * Wires the runner.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * Counts one call in a window of `$interval` seconds (rounded up to minutes); false once `$limit` calls were
     * counted in it.
     */
    public function take(TenantRef $tenant, DateTimeImmutable $now, int $limit, int $interval, string $window, string $counter): bool
    {
        $minute = intdiv($now->getTimestamp(), 60);
        $start  = $this->locked($tenant, $window);
        $count  = $this->locked($tenant, $counter);
        if ($start === null || $minute - $start >= intdiv($interval + 59, 60)) {
            $this->set($tenant, $window, $minute);
            $this->set($tenant, $counter, 1);

            return true;
        }
        if ($count >= $limit) {
            return false;
        }
        $this->set($tenant, $counter, ($count ?? 0) + 1);

        return true;
    }

    /**
     * A counter row's value under its lock; null when it did not exist (it is then created at 0 and locked).
     */
    private function locked(TenantRef $tenant, string $resource): ?int
    {
        $db    = $this->db();
        $table = $this->table();
        $sql   = 'SELECT value FROM ' . $table . ' WHERE tenant_id = ? AND resource = ?' . ($db->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE');
        $value = $db->fetchOne($sql, [$tenant->value, $resource]);
        if (is_numeric($value)) {
            return (int) $value;
        }
        $platform = $db->getDatabasePlatform();
        $insert   = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, 0)',
            $platform instanceof PostgreSQLPlatform    => 'INSERT INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, 0) ON CONFLICT DO NOTHING',
            default                                    => 'INSERT OR IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, 0)',
        };
        $db->executeStatement($insert, [$tenant->value, $resource]);
        $db->fetchOne($sql, [$tenant->value, $resource]);

        return null;
    }

    private function set(TenantRef $tenant, string $resource, int $value): void
    {
        $this->db()->executeStatement('UPDATE ' . $this->table() . ' SET value = ? WHERE tenant_id = ? AND resource = ?', [$value, $tenant->value, $resource], [0 => ParameterType::INTEGER]);
    }

    private function table(): string
    {
        return $this->runner->manager()->getClassMetadata(Counter::class)->getTableName();
    }

    private function db(): Connection
    {
        return $this->runner->manager()->getConnection();
    }
}
