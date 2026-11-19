<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Port\CrmBatchLookupStoreInterface;
use Nowo\CrmKitBundle\Domain\Read\RolesAndTags;
use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;
use Nowo\CrmKitBundle\Domain\Value\CompanyDomain;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_chunk;
use function array_fill_keys;
use function array_intersect_key;
use function array_keys;
use function array_replace;
use function array_values;
use function in_array;
use function sort;

use const SORT_STRING;

/**
 * {@see CrmBatchLookupInterface}: normalises and de-duplicates the inputs, runs one store query per chunk and gives
 * every usable input a result (empty when nothing matched). No tenant resolution and no access checks: a system API
 * for the host's backend jobs. Stateless (worker mode), read-only.
 */
final readonly class CrmBatchLookup implements CrmBatchLookupInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmBatchLookupStoreInterface $store, private PartyCatalogue $catalogue)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function contactsByEmails(TenantRef $tenant, array $emails): array
    {
        $keys = [];
        foreach ($emails as $email) {
            $key = EmailAddress::lookupKey($email);
            if ($key !== null) {
                $keys[$key] = [];
            }
        }

        return $this->collect($keys, fn (array $chunk): array => $this->store->contactIdsByEmailKeys($tenant, $chunk));
    }

    /**
     * {@inheritDoc}
     */
    public function companiesByDomains(TenantRef $tenant, array $domains): array
    {
        $keys = [];
        foreach ($domains as $domain) {
            $key = CompanyDomain::key($domain);
            if ($key !== null) {
                $keys[$key] = [];
            }
        }

        return $this->collect($keys, fn (array $chunk): array => $this->store->companyIdsByDomainKeys($tenant, $chunk));
    }

    /**
     * {@inheritDoc}
     */
    public function subjectsLinkedToMany(TenantRef $tenant, array $hosts): array
    {
        $byKey = [];
        foreach ($hosts as $host) {
            $byKey[$host->key()] = $host;
        }
        $result = array_fill_keys(array_keys($byKey), []);
        foreach (array_chunk(array_values($byKey), self::CHUNK) as $chunk) {
            // Only asked keys are taken (a port that returns more cannot add any).
            $result = array_replace($result, array_intersect_key($this->store->activeSubjectsLinkedTo($tenant, $chunk), $result));
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function affiliationsOf(TenantRef $tenant, array $contactIds): array
    {
        $keys = [];
        foreach ($contactIds as $id) {
            if (RecordId::isValid($id)) {
                $keys[$id] = [];
            }
        }

        return $this->collect($keys, fn (array $chunk): array => $this->store->affiliatedCompanies($tenant, $chunk));
    }

    /**
     * {@inheritDoc}
     */
    public function rolesAndTagsOf(TenantRef $tenant, array $subjects): array
    {
        $byKey = [];
        foreach ($subjects as $subject) {
            $byKey[$subject->key()] = $subject;
        }
        $result = array_fill_keys(array_keys($byKey), new RolesAndTags());
        $order  = $this->catalogue->roles();
        foreach (array_chunk(array_values($byKey), self::CHUNK) as $chunk) {
            foreach (array_intersect_key($this->store->rolesAndTags($tenant, $chunk), $result) as $key => $row) {
                // Configured roles only, in catalogue order; tags in byte order.
                $roles = [];
                foreach ($order as $role) {
                    if (in_array($role, $row['roles'], true)) {
                        $roles[] = $role;
                    }
                }
                $tags = $row['tags'];
                sort($tags, SORT_STRING);
                $result[$key] = new RolesAndTags($roles, $tags);
            }
        }

        return $result;
    }

    /**
     * Runs `$query` per chunk of the keys and fills their lists (unknown keys from the store are ignored).
     *
     * @param array<string, list<string>> $keys every usable input, with an empty list
     * @param callable(non-empty-list<string>): array<string, list<string>> $query
     *
     * @return array<string, list<string>>
     */
    private function collect(array $keys, callable $query): array
    {
        foreach (array_chunk(array_keys($keys), self::CHUNK) as $chunk) {
            $keys = array_replace($keys, array_intersect_key($query($chunk), $keys));
        }

        return $keys;
    }
}
