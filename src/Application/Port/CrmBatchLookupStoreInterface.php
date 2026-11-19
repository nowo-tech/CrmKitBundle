<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

use Nowo\CrmKitBundle\Application\CrmBatchLookup;
use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The read queries behind {@see CrmBatchLookup}: one query per call, the inputs already
 * normalised, de-duplicated and at most {@see CrmBatchLookupInterface::CHUNK}
 * long. Every query names the tenant and every row is re-checked against it with `===` (REQ-SEC-006); only active
 * records are returned except by {@see self::rolesAndTags()} and {@see self::existingKeys()}.
 *
 * @internal
 */
interface CrmBatchLookupStoreInterface
{
    /**
     * Ids of the active contacts per e-mail key, ascending.
     *
     * @param non-empty-list<string> $emailKeys
     *
     * @return array<string, list<string>> only keys that matched
     */
    public function contactIdsByEmailKeys(TenantRef $tenant, array $emailKeys): array;

    /**
     * Ids of the active companies per domain key, ascending.
     *
     * @param non-empty-list<string> $domainKeys
     *
     * @return array<string, list<string>> only keys that matched
     */
    public function companyIdsByDomainKeys(TenantRef $tenant, array $domainKeys): array;

    /**
     * The active records linked to each host object, by link id (creation order), with their owners.
     *
     * @param non-empty-list<HostObjectRef> $hosts
     *
     * @return array<string, list<SubjectRef>> by {@see HostObjectRef::key()}, only hosts that matched
     */
    public function activeSubjectsLinkedTo(TenantRef $tenant, array $hosts): array;

    /**
     * Company ids of the active contacts' affiliations with active companies: primary first, then by affiliation date
     * and company id.
     *
     * @param non-empty-list<string> $contactIds canonical record ids
     *
     * @return array<string, list<string>> only contacts that matched
     */
    public function affiliatedCompanies(TenantRef $tenant, array $contactIds): array;

    /**
     * Every stored role key and tag name key of the records (configured or not; the caller filters).
     *
     * @param non-empty-list<SubjectRef> $subjects
     *
     * @return array<string, array{roles: list<string>, tags: list<string>}> by {@see SubjectRef::key()}, only records
     *                                                                       that hold one
     */
    public function rolesAndTags(TenantRef $tenant, array $subjects): array;

    /**
     * The {@see SubjectRef::key()} of the records whose row exists in the tenant, any status.
     *
     * @param non-empty-list<SubjectRef> $subjects
     *
     * @return list<string>
     */
    public function existingKeys(TenantRef $tenant, array $subjects): array;
}
