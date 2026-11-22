<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Nowo\CrmKitBundle\Application\Port\CrmActorDataStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\MergeRecord;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;

use function array_column;
use function array_map;
use function count;
use function end;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function strlen;
use function usort;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * {@see CrmActorDataStoreInterface} in plain SQL on the bundle's connection: an id column is matched with `=` (binary
 * collation on MySQL / MariaDB); an owner inside a JSON document (owner-changed timeline entries, merge snapshots) is
 * found with a LIKE pre-filter on the id's longest plain run and confirmed after decoding. Each page is read without a
 * lock; in write mode only its matched rows are then locked by primary key (`FOR UPDATE`) and checked again before they
 * change, and a versioned row's version moves, so a form opened before
 * keeps failing as stale instead of writing the old id back. No entity is loaded. Stateless.
 *
 * @internal
 */
final readonly class DoctrineCrmActorDataStore implements CrmActorDataStoreInterface
{
    /**
     * kind ⇒ [entity, column, key column, versioned, JSON path key or null, timeline kind or null].
     */
    private const MAP = [
        'company.owner'        => [Company::class, 'owner_id', 'id', true, null],
        'company.created_by'   => [Company::class, 'created_by_id', 'id', true, null],
        'company.updated_by'   => [Company::class, 'updated_by_id', 'id', true, null],
        'contact.owner'        => [Contact::class, 'owner_id', 'id', true, null],
        'contact.created_by'   => [Contact::class, 'created_by_id', 'id', true, null],
        'contact.updated_by'   => [Contact::class, 'updated_by_id', 'id', true, null],
        'note.author'          => [Note::class, 'author_id', 'id', true, null],
        'activity.actor'       => [Activity::class, 'actor_id', 'id', false, null],
        'activity.owner'       => [Activity::class, 'params', 'id', false, 'owner'],
        'consent.recorded_by'  => [Consent::class, 'recorded_by_id', 'id', false, null],
        'host_link.created_by' => [HostLink::class, 'created_by_id', 'id', false, null],
        'role.created_by'      => [RoleAssignment::class, 'created_by_id', 'id', false, null],
        'import.created_by'    => [Import::class, 'created_by_id', 'id', true, null],
        'merge.actor'          => [MergeRecord::class, 'actor_id', 'id', false, null],
        'merge.owner'          => [MergeRecord::class, 'snapshot', 'id', false, 'owner'],
        'settings.updated_by'  => [CrmSettings::class, 'updated_by_id', 'tenant_id', true, null],
    ];

    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function scan(string $kind, string $actorId, ?string $after, int $limit, ?string $replacement = null): array
    {
        [$class, $column, $key, $versioned, $path] = self::MAP[$kind] ?? throw new LogicException(sprintf('Unknown actor reference kind "%s".', $kind));
        $em                                        = $this->runner->manager();
        $db                                        = $em->getConnection();
        $table                                     = $em->getClassMetadata($class)->getTableName();
        // PostgreSQL has no LIKE on json: compare its text.
        $text  = $db->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'CAST(' . $column . ' AS TEXT)' : $column;
        $where = [];
        $args  = [];
        if ($path === null) {
            $where[] = $column . ' = ?';
            $args[]  = $actorId;
        } else {
            // JSON: a LIKE pre-filter on the id's longest plain run (present verbatim in every JSON encoding), confirmed below.
            $probe = $this->probe($actorId);
            if ($probe !== '') {
                $where[] = 'LOWER(' . $text . ") LIKE LOWER(?) ESCAPE '!'";
                $args[]  = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $probe) . '%';
            }
            if ($class === Activity::class) {
                $where[] = 'kind = ?';
                $args[]  = ActivityKind::OwnerChanged->value;
            }
        }
        if ($after !== null) {
            $where[] = $key . ' > ?';
            $args[]  = $after;
        }
        $rows = $db->fetchAllAssociative(
            // A keyset page read without a lock: the actor columns have no index, so a locking scan would lock every row
            // it reads in every tenant on InnoDB (audit VD-2).
            sprintf('SELECT %s AS row_key, tenant_id, %s AS ref FROM %s%s ORDER BY %s LIMIT %d', $key, $column, $table, $where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $key, $limit),
            $args,
        );
        $found = $this->matches($rows, $path, $actorId);
        if ($replacement !== null && $found !== []) {
            // Then only the matched rows, by primary key, are locked and checked again before they change.
            $lock   = $db->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
            $locked = $db->fetchAllAssociative(
                sprintf('SELECT %s AS row_key, tenant_id, %s AS ref FROM %s WHERE %s IN (?) ORDER BY %s%s', $key, $column, $table, $key, $key, $lock),
                [array_column($found, 'key')],
                [ArrayParameterType::STRING],
            );
            $found = $this->matches($locked, $path, $actorId);
            $this->replace($db, $table, $column, $key, $versioned, $path, $actorId, $replacement, $found);
        }
        $last = $rows === [] ? null : end($rows)['row_key'];

        return [
            'rows' => array_map(static fn (array $f): array => ['tenant' => $f['tenant'], 'key' => $f['key']], $found),
            'next' => count($rows) === $limit && is_string($last) ? $last : null,
        ];
    }

    /**
     * The rows that reference the person exactly (an id column with ===, a JSON owner after decoding).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{tenant: string, key: string, document: array<mixed>|null}>
     */
    private function matches(array $rows, ?string $path, string $actorId): array
    {
        $found = [];
        foreach ($rows as $row) {
            $document = $path === null ? null : $this->document($row['ref']);
            $match    = $path === null ? $row['ref'] === $actorId : is_array($document) && ($document[$path] ?? null) === $actorId;
            if ($match && is_string($row['row_key']) && is_string($row['tenant_id'])) {
                $found[] = ['tenant' => $row['tenant_id'], 'key' => $row['row_key'], 'document' => $document];
            }
        }

        return $found;
    }

    /**
     * Writes the replacement into the matched rows (still referencing the person: re-checked in the UPDATE).
     *
     * @param list<array{tenant: string, key: string, document: array<mixed>|null}> $found
     */
    private function replace(Connection $db, string $table, string $column, string $key, bool $versioned, ?string $path, string $actorId, string $replacement, array $found): void
    {
        $version = $versioned ? ', version = version + 1' : '';
        if ($path === null) {
            if ($found !== []) {
                $db->executeStatement(
                    sprintf('UPDATE %s SET %s = ?%s WHERE %s IN (?) AND %s = ?', $table, $column, $version, $key, $column),
                    [$replacement, array_column($found, 'key'), $actorId],
                    [ParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING],
                );
            }

            return;
        }
        foreach ($found as $row) {
            $document        = (array) $row['document'];
            $document[$path] = $replacement;
            $db->executeStatement(sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $key), [json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $row['key']]);
        }
    }

    /**
     * The longest run of characters every JSON encoder writes verbatim.
     */
    private function probe(string $id): string
    {
        preg_match_all('/[A-Za-z0-9@.+-]+/', $id, $runs);
        $runs = $runs[0];
        usort($runs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $runs[0] ?? '';
    }

    /**
     * A JSON column value as an array (null when it is not one).
     *
     * @return array<mixed>|null
     */
    private function document(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : null;
    }
}
