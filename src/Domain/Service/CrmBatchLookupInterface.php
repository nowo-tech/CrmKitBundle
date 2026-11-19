<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Read\RolesAndTags;
use Nowo\CrmKitBundle\Domain\Value\CompanyDomain;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Batch lookups for the host's backend jobs (a SYSTEM API; contract section 7, 0.2.1).
 *
 * Every method is scoped to the given tenant and runs a single read query per chunk of {@see self::CHUNK} inputs (no
 * N+1). It does NOT resolve the tenant, the feature gate or the actor and checks NO permission and NO record
 * visibility (`security.resource_scoping`): its callers are trusted server-side jobs. A host must never pass a
 * tenant or input taken from a request to it, nor show its results to a person, without its own checks.
 *
 * Results are keyed by the normalised input (every usable input appears, with an empty list when nothing matches;
 * unusable inputs are skipped). Only active records are returned: trashed, merged (tombstones) and erased records
 * never are. Read-only, stateless (worker mode).
 */
interface CrmBatchLookupInterface
{
    /** Inputs per query; longer lists run one query per chunk. */
    public const CHUNK = 500;

    /**
     * Active contacts by e-mail. Keys are the inputs trimmed and lowercased ({@see EmailAddress::lookupKey()},
     * the stored contact e-mail key); values the contact ids, ascending (a list: today at most one per address, as the
     * key is unique per tenant).
     *
     * @param list<string> $emails
     *
     * @return array<string, list<string>>
     */
    public function contactsByEmails(TenantRef $tenant, array $emails): array;

    /**
     * Active companies by domain. Keys are the inputs as domain keys
     * ({@see CompanyDomain::key()}: lowercase host without `www.`, a URL is accepted);
     * values the company ids, ascending (several companies may share a domain). Free-mail domains are not filtered:
     * that is the caller's concern.
     *
     * @param list<string> $domains
     *
     * @return array<string, list<string>>
     */
    public function companiesByDomains(TenantRef $tenant, array $domains): array;

    /**
     * The batch form of {@see HostLinkServiceInterface::subjectsLinkedTo()} without its permission and visibility
     * checks: the active companies and contacts linked to each host object, by link creation order. Keys are
     * {@see HostObjectRef::key()} (`<type>:<id>`); each {@see SubjectRef} carries the record's owner.
     *
     * @param list<HostObjectRef> $hosts
     *
     * @return array<string, list<SubjectRef>>
     */
    public function subjectsLinkedToMany(TenantRef $tenant, array $hosts): array;

    /**
     * The active companies each active contact is affiliated with: the primary affiliation first, then by affiliation
     * date and company id. Keys are the contact ids (inputs that are not canonical lowercase UUIDs are skipped).
     *
     * @param list<string> $contactIds
     *
     * @return array<string, list<string>>
     */
    public function affiliationsOf(TenantRef $tenant, array $contactIds): array;

    /**
     * The configured party roles and the tags of each record (whatever its status). Keys are {@see SubjectRef::key()}
     * (`<type>:<id>`).
     *
     * @param list<SubjectRef> $subjects
     *
     * @return array<string, RolesAndTags>
     */
    public function rolesAndTagsOf(TenantRef $tenant, array $subjects): array;
}
