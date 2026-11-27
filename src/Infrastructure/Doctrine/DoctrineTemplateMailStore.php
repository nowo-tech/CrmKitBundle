<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\CrmKitBundle\Application\Templates\Port\TemplateMailStoreInterface;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Counter;
use Nowo\CrmKitBundle\Domain\Model\Template\TemplatePurpose;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function hash;
use function in_array;
use function intdiv;
use function is_array;
use function substr;

/**
 * {@see TemplateMailStoreInterface} on the bundle's entity manager (0.13.0): the classification rows
 * ({@see TemplatePurpose}, table `crm_template_purpose`) and, in `crm_counter`, the send window of a person (a hash of
 * the id, as the mention endpoints) and the send keys (`m:<key>`, value = the claim's minute). Every query names the
 * tenant and every row is re-checked with `===` (REQ-SEC-006 rule 2). Stateless: the entity manager comes from the
 * runner on every call.
 *
 * @internal
 */
final readonly class DoctrineTemplateMailStore implements TemplateMailStoreInterface
{
    /** Prefix of a send key's counter row (the key is 30 characters: the row key holds 32). */
    public const KEY_PREFIX = 'm:';

    /** Minutes a claimed key is kept. */
    public const KEY_MINUTES = 1440;

    /**
     * Wires the runner.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function classifications(TenantRef $tenant, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->runner->manager()->createQueryBuilder()->select('p')->from(TemplatePurpose::class, 'p')
            ->where('p.tenantId = :tenant')->andWhere('p.id IN (:ids)')
            ->setParameter('tenant', $tenant->value)->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()->getResult();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($row instanceof TemplatePurpose && $row->getTenantId() === $tenant->value && in_array($row->getId(), $ids, true)) {
                $out[$row->getId()] = $row->getPurpose();
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function classify(TenantRef $tenant, string $id, ?string $purpose, DateTimeImmutable $now): void
    {
        $manager = $this->runner->manager();
        $row     = $manager->find(TemplatePurpose::class, $id);
        if ($row instanceof TemplatePurpose) {
            if ($row->getTenantId() !== $tenant->value || $row->getId() !== $id) {
                // Another tenant's row under the same id (template ids are UUIDs: never expected).
                throw new NotAvailableException();
            }
            $row->classify($purpose, $now);

            return;
        }
        $manager->persist(new TemplatePurpose($id, $tenant->value, $purpose, $now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $tenant, string $id): void
    {
        $table = $this->runner->manager()->getClassMetadata(TemplatePurpose::class)->getTableName();
        $this->db()->executeStatement('DELETE FROM ' . $table . ' WHERE id = ? AND tenant_id = ?', [$id, $tenant]);
    }

    /**
     * {@inheritDoc}
     */
    public function takeSend(TenantRef $tenant, string $actorId, DateTimeImmutable $now, int $limit, int $interval): bool
    {
        // One counter pair per person (a hash of the id: the counter key holds 32 characters).
        $key = substr(hash('sha256', $actorId), 0, 24);

        return (new DoctrineCounterWindow($this->runner))->take($tenant, $now, $limit, $interval, 'sw_' . $key, 'sc_' . $key);
    }

    /**
     * {@inheritDoc}
     */
    public function claim(TenantRef $tenant, string $key, DateTimeImmutable $now): bool
    {
        $db     = $this->db();
        $table  = $this->counters();
        $minute = intdiv($now->getTimestamp(), 60);
        // Old claims of the tenant go first (a bounded table without a purge job).
        $db->executeStatement('DELETE FROM ' . $table . ' WHERE tenant_id = ? AND resource LIKE ? AND value < ?', [$tenant->value, self::KEY_PREFIX . '%', $minute - self::KEY_MINUTES], [2 => ParameterType::INTEGER]);
        $platform = $db->getDatabasePlatform();
        // One statement that never fails on a taken key (a failed INSERT would abort a PostgreSQL transaction).
        $insert = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, ?)',
            $platform instanceof PostgreSQLPlatform    => 'INSERT INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            default                                    => 'INSERT OR IGNORE INTO ' . $table . ' (tenant_id, resource, value) VALUES (?, ?, ?)',
        };

        return $db->executeStatement($insert, [$tenant->value, self::KEY_PREFIX . $key, $minute], [2 => ParameterType::INTEGER]) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function release(TenantRef $tenant, string $key): void
    {
        $this->runner->write(function () use ($tenant, $key): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->db()->executeStatement('DELETE FROM ' . $this->counters() . ' WHERE tenant_id = ? AND resource = ?', [$tenant->value, self::KEY_PREFIX . $key]);
        });
    }

    private function counters(): string
    {
        return $this->runner->manager()->getClassMetadata(Counter::class)->getTableName();
    }

    private function db(): Connection
    {
        return $this->runner->manager()->getConnection();
    }
}
