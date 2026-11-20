<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine\Api;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use JsonException;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Operation\CrmApiIdempotency;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyClaim;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyRecord;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyStoreInterface;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Domain\Model\Api\ApiIdempotency;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;

use function array_is_list;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The idempotency store of the API on the bundle's connection (0.3.0, design §8.2): the claim is an insert that never
 * aborts a PostgreSQL transaction (`ON CONFLICT DO NOTHING`, MySQL `ON DUPLICATE KEY UPDATE scope_hash = scope_hash`,
 * SQLite `INSERT OR IGNORE`); when it inserts nothing, the other writer's row is read `FOR SHARE` (it committed: the
 * insert waited on its key lock). Ids and outcomes only. A transient lock failure reaches the write unit as is; any
 * other failure is 503 `IDEMP_004`. Stateless.
 */
final readonly class DoctrineCrmApiIdempotencyStore implements CrmApiIdempotencyStoreInterface
{
    /**
     * Wires the runner (its entity manager's connection is the write unit's).
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function claim(CrmApiIdempotencyClaim $claim): ?CrmApiIdempotencyRecord
    {
        return $this->guard(function () use ($claim): ?CrmApiIdempotencyRecord {
            $connection = $this->connection();
            $table      = $this->table();
            $connection->executeStatement('DELETE FROM ' . $table . ' WHERE scope_hash = ? AND key_hash = ? AND expires_at < ?', [$claim->scopeHash, $claim->keyHash, $this->second($claim->now)]);
            $columns  = 'scope_hash, key_hash, tenant_id, operation, fingerprint, outcome, result_ids, created_at, expires_at';
            $values   = '?, ?, ?, ?, ?, NULL, NULL, ?, ?';
            $platform = $connection->getDatabasePlatform();
            $sql      = match (true) {
                $platform instanceof PostgreSQLPlatform    => 'INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . $values . ') ON CONFLICT DO NOTHING',
                $platform instanceof AbstractMySQLPlatform => 'INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . $values . ') ON DUPLICATE KEY UPDATE scope_hash = scope_hash',
                default                                    => 'INSERT OR IGNORE INTO ' . $table . ' (' . $columns . ') VALUES (' . $values . ')',
            };
            $inserted = $connection->executeStatement($sql, [$claim->scopeHash, $claim->keyHash, $claim->tenant, $claim->operation, $claim->fingerprint, $this->micro($claim->now), $this->second($claim->expiresAt)]);

            return $inserted === 1 ? null : ($this->row($claim->scopeHash, $claim->keyHash, null, true) ?? throw CrmApiRefusal::of(CrmApiCode::IDEMP_STORE));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $scopeHash, string $keyHash, DateTimeImmutable $now): ?CrmApiIdempotencyRecord
    {
        return $this->guard(fn (): ?CrmApiIdempotencyRecord => $this->runner->read(fn (): ?CrmApiIdempotencyRecord => $this->row($scopeHash, $keyHash, $now, false)));
    }

    /**
     * {@inheritDoc}
     */
    public function complete(string $scopeHash, string $keyHash, int $status, array $resultIds): void
    {
        $this->guard(fn (): int => (int) $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET outcome = ?, result_ids = ? WHERE scope_hash = ? AND key_hash = ?',
            [$status, json_encode($resultIds === [] ? (object) [] : $resultIds, JSON_THROW_ON_ERROR), $scopeHash, $keyHash],
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $channel, string $tokenId): int
    {
        return $this->guard(fn (): int => (int) $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE scope_hash = ?', [CrmApiIdempotency::scope($channel, $tokenId)]));
    }

    /**
     * {@inheritDoc}
     */
    public function purgeExpired(DateTimeImmutable $now, int $limit): int
    {
        return $this->guard(function () use ($now, $limit): int {
            $connection = $this->connection();
            $rows       = $connection->fetchAllAssociative('SELECT scope_hash, key_hash FROM ' . $this->table() . ' WHERE expires_at < ? ORDER BY expires_at LIMIT ' . max(1, $limit), [$this->second($now)]);
            $deleted    = 0;
            foreach ($rows as $row) {
                $deleted += (int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE scope_hash = ? AND key_hash = ? AND expires_at < ?', [$row['scope_hash'], $row['key_hash'], $this->second($now)]);
            }

            return $deleted;
        });
    }

    /**
     * One row, locked for share when asked; an expired one (when `$now` is given) is absent.
     */
    private function row(string $scopeHash, string $keyHash, ?DateTimeImmutable $now, bool $lock): ?CrmApiIdempotencyRecord
    {
        $connection = $this->connection();
        $platform   = $connection->getDatabasePlatform();
        $suffix     = !$lock || $platform instanceof SQLitePlatform ? '' : ($platform instanceof MariaDBPlatform ? ' LOCK IN SHARE MODE' : ' FOR SHARE');
        $row        = $connection->fetchAssociative('SELECT operation, fingerprint, outcome, result_ids, expires_at FROM ' . $this->table() . ' WHERE scope_hash = ? AND key_hash = ?' . $suffix, [$scopeHash, $keyHash]);
        if (!is_array($row) || !is_string($row['operation'] ?? null) || !is_string($row['fingerprint'] ?? null)) {
            return null;
        }
        if ($now instanceof DateTimeImmutable && is_string($row['expires_at'] ?? null) && $row['expires_at'] < $this->second($now)) {
            return null;
        }
        $ids = [];
        if (is_string($row['result_ids'] ?? null)) {
            $decoded = json_decode($row['result_ids'], true, 4);
            foreach (is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : [] as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $ids[$name] = $value;
                }
            }
        }
        $outcome = $row['outcome'] ?? null;

        return new CrmApiIdempotencyRecord($row['operation'], $row['fingerprint'], is_numeric($outcome) ? (int) $outcome : null, $ids);
    }

    /**
     * Runs a statement; transient failures pass, any other failure is `IDEMP_004`.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (DbalException|JsonException $e) {
            throw CrmReader::transient($e) ? $e : CrmApiRefusal::of(CrmApiCode::IDEMP_STORE, [], $e);
        }
    }

    private function table(): string
    {
        return $this->runner->manager()->getClassMetadata(ApiIdempotency::class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->runner->manager()->getConnection();
    }

    private function second(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function micro(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
