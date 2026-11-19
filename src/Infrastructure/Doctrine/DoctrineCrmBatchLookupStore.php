<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\CrmKitBundle\Application\Port\CrmBatchLookupStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_fill_keys;
use function implode;
use function is_string;

/**
 * {@see CrmBatchLookupStoreInterface} in plain SQL on the bundle's connection: one SELECT per call (joins and a
 * UNION ALL instead of follow-up queries), no entity hydrated, so the unit of work is never touched. Every query names
 * the tenant; every row is re-checked in PHP with `===` against the tenant and the asked keys (REQ-SEC-006 rule 2).
 * Stateless: the entity manager comes from the runner on every call.
 *
 * @internal
 */
final readonly class DoctrineCrmBatchLookupStore implements CrmBatchLookupStoreInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function contactIdsByEmailKeys(TenantRef $tenant, array $emailKeys): array
    {
        return $this->idsByKey(Contact::class, 'email_key', $tenant, $emailKeys);
    }

    /**
     * {@inheritDoc}
     */
    public function companyIdsByDomainKeys(TenantRef $tenant, array $domainKeys): array
    {
        return $this->idsByKey(Company::class, 'domain_key', $tenant, $domainKeys);
    }

    /**
     * {@inheritDoc}
     */
    public function activeSubjectsLinkedTo(TenantRef $tenant, array $hosts): array
    {
        $em     = $this->em();
        $asked  = [];
        $byType = [];
        foreach ($hosts as $host) {
            $asked[$host->key()]   = true;
            $byType[$host->type][] = $host->id;
        }
        $params = [SubjectType::Contact->value, RecordStatus::Active->value, SubjectType::Company->value, RecordStatus::Active->value, $tenant->value];
        $types  = [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
        $pairs  = [];
        foreach ($byType as $type => $ids) {
            $pairs[]  = '(l.host_type = ? AND l.host_id IN (?))';
            $params[] = (string) $type;
            $params[] = $ids;
            $types[]  = ParameterType::STRING;
            $types[]  = ArrayParameterType::STRING;
        }
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT l.tenant_id, l.subject_type, l.subject_id, l.host_type, l.host_id, c.tenant_id AS contact_tenant, c.owner_id AS contact_owner, co.tenant_id AS company_tenant, co.owner_id AS company_owner'
            . ' FROM ' . $this->table($em, HostLink::class) . ' l'
            . ' LEFT JOIN ' . $this->table($em, Contact::class) . ' c ON l.subject_type = ? AND c.id = l.subject_id AND c.tenant_id = l.tenant_id AND c.status = ?'
            . ' LEFT JOIN ' . $this->table($em, Company::class) . ' co ON l.subject_type = ? AND co.id = l.subject_id AND co.tenant_id = l.tenant_id AND co.status = ?'
            . ' WHERE l.tenant_id = ? AND (c.id IS NOT NULL OR co.id IS NOT NULL) AND (' . implode(' OR ', $pairs) . ')'
            . ' ORDER BY l.id',
            $params,
            $types,
        );
        $result = [];
        foreach ($rows as $row) {
            $key                    = $this->text($row['host_type']) . ':' . $this->text($row['host_id']);
            $type                   = is_string($row['subject_type']) ? SubjectType::tryFrom($row['subject_type']) : null;
            [$recordTenant, $owner] = $type === SubjectType::Contact ? [$row['contact_tenant'], $row['contact_owner']] : [$row['company_tenant'], $row['company_owner']];
            if ($type instanceof SubjectType && isset($asked[$key]) && is_string($row['subject_id']) && $row['tenant_id'] === $tenant->value && $recordTenant === $tenant->value) {
                $result[$key][] = SubjectRef::of($type, $row['subject_id'], is_string($owner) ? $owner : null);
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function affiliatedCompanies(TenantRef $tenant, array $contactIds): array
    {
        $em   = $this->em();
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT a.tenant_id, a.contact_id, a.company_id, c.tenant_id AS contact_tenant, co.tenant_id AS company_tenant'
            . ' FROM ' . $this->table($em, Affiliation::class) . ' a'
            . ' INNER JOIN ' . $this->table($em, Contact::class) . ' c ON c.id = a.contact_id AND c.tenant_id = a.tenant_id'
            . ' INNER JOIN ' . $this->table($em, Company::class) . ' co ON co.id = a.company_id AND co.tenant_id = a.tenant_id'
            . ' WHERE a.tenant_id = ? AND a.contact_id IN (?) AND c.status = ? AND co.status = ?'
            . ' ORDER BY a.contact_id, a.is_primary DESC, a.created_at, a.company_id',
            [$tenant->value, $contactIds, RecordStatus::Active->value, RecordStatus::Active->value],
            [ParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        $asked  = array_fill_keys($contactIds, true);
        $result = [];
        foreach ($rows as $row) {
            $contact = $row['contact_id'];
            if (is_string($contact) && isset($asked[$contact]) && is_string($row['company_id']) && $row['tenant_id'] === $tenant->value && $row['contact_tenant'] === $tenant->value && $row['company_tenant'] === $tenant->value) {
                $result[$contact][] = $row['company_id'];
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function rolesAndTags(TenantRef $tenant, array $subjects): array
    {
        $em     = $this->em();
        $asked  = [];
        $byType = [];
        foreach ($subjects as $subject) {
            $asked[$subject->key()]          = true;
            $byType[$subject->type->value][] = $subject->id->value;
        }
        $roles = $this->subjectFilter('r', $byType);
        $tags  = $this->subjectFilter('g', $byType);
        $rows  = $em->getConnection()->fetchAllAssociative(
            "SELECT 'r' AS kind, r.tenant_id, r.subject_type, r.subject_id, r.role_key AS value_key FROM " . $this->table($em, RoleAssignment::class) . ' r'
            . ' WHERE r.tenant_id = ? AND (' . $roles[0] . ')'
            . " UNION ALL SELECT 't' AS kind, g.tenant_id, g.subject_type, g.subject_id, t.name_key AS value_key FROM " . $this->table($em, Tagging::class) . ' g'
            . ' INNER JOIN ' . $this->table($em, Tag::class) . ' t ON t.id = g.tag_id AND t.tenant_id = g.tenant_id'
            . ' WHERE g.tenant_id = ? AND (' . $tags[0] . ')',
            [$tenant->value, ...$roles[1], $tenant->value, ...$tags[1]],
            [ParameterType::STRING, ...$roles[2], ParameterType::STRING, ...$tags[2]],
        );
        $result = [];
        foreach ($rows as $row) {
            $key = $this->text($row['subject_type']) . ':' . $this->text($row['subject_id']);
            if (isset($asked[$key]) && is_string($row['value_key']) && $row['tenant_id'] === $tenant->value) {
                $result[$key] ??= ['roles' => [], 'tags' => []];
                $result[$key][$row['kind'] === 'r' ? 'roles' : 'tags'][] = $row['value_key'];
            }
        }

        return $result;
    }

    /**
     * Ids of the tenant's active records of `$class` whose `$column` is one of `$keys`, per key, ascending.
     *
     * @param class-string $class
     * @param non-empty-list<string> $keys
     *
     * @return array<string, list<string>>
     */
    private function idsByKey(string $class, string $column, TenantRef $tenant, array $keys): array
    {
        $em   = $this->em();
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT tenant_id, id, ' . $column . ' AS lookup_key FROM ' . $this->table($em, $class) . ' WHERE tenant_id = ? AND status = ? AND ' . $column . ' IN (?) ORDER BY id',
            [$tenant->value, RecordStatus::Active->value, $keys],
            [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING],
        );
        $asked  = array_fill_keys($keys, true);
        $result = [];
        foreach ($rows as $row) {
            $key = $row['lookup_key'];
            if ($row['tenant_id'] === $tenant->value && is_string($key) && isset($asked[$key]) && is_string($row['id'])) {
                $result[$key][] = $row['id'];
            }
        }

        return $result;
    }

    /**
     * The `(subject_type = ? AND subject_id IN (?)) OR …` filter of `$alias` with its parameters and types.
     *
     * @param array<string, list<string>> $byType record ids per subject type
     *
     * @return array{string, list<list<string>|string>, list<ArrayParameterType|ParameterType>}
     */
    private function subjectFilter(string $alias, array $byType): array
    {
        $sql    = [];
        $params = [];
        $types  = [];
        foreach ($byType as $type => $ids) {
            $sql[]    = '(' . $alias . '.subject_type = ? AND ' . $alias . '.subject_id IN (?))';
            $params[] = $type;
            $params[] = $ids;
            $types[]  = ParameterType::STRING;
            $types[]  = ArrayParameterType::STRING;
        }

        return [implode(' OR ', $sql), $params, $types];
    }

    /**
     * The table of an entity class (with the configured prefix).
     *
     * @param class-string $class
     */
    private function table(EntityManagerInterface $em, string $class): string
    {
        return $em->getClassMetadata($class)->getTableName();
    }

    /**
     * A column value as text (a non-string never matches an asked key).
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : "\0";
    }

    private function em(): EntityManagerInterface
    {
        return $this->runner->manager();
    }
}
