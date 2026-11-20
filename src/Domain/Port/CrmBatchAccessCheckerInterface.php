<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * An access checker that decides a page of records at once (0.3.0): the bundle's default implements it, so lists,
 * searches, labels and timelines pay one area check and one visibility per page and only the record decision per row.
 * A host checker that does not implement it is asked per record.
 */
interface CrmBatchAccessCheckerInterface extends CrmAccessCheckerInterface
{
    /**
     * The keys (`SubjectRef::key()`) of the records on which the permission is held.
     *
     * @param list<SubjectRef> $subjects
     *
     * @return array<string, true>
     */
    public function grantedSubjects(CrmPermission $permission, TenantRef $tenant, array $subjects): array;
}
