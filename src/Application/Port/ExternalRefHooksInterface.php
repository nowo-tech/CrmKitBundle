<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * What the record housekeeping does to the external references (0.10.0, only while `import.hubspot.enabled`), inside
 * the caller's unit: a merge moves them to the survivor, a purge deletes them (tombstones excepted), a GDPR erasure
 * turns them into tombstones (Q-H6).
 *
 * @internal
 */
interface ExternalRefHooksInterface
{
    /** The references of a merged record now point at the survivor. */
    public function move(TenantRef $tenant, SubjectType $type, string $fromId, string $toId): void;

    /** The references of a purged record are deleted (an erased person's tombstone stays). */
    public function forget(TenantRef $tenant, SubjectType $type, string $id): void;

    /** The references of an erased contact become tombstones (`erased`, kept to skip the person on re-runs). */
    public function tombstone(TenantRef $tenant, string $contactId): void;

    /**
     * 0.12.0 (audit ZDE-M2): the references of the imported notes, the activities whose primary record is the contact
     * (`$activities`: `activities.enabled`) and its next actions (`$nextActions`: `pipeline.enabled`) become tombstones,
     * before the erasure deletes them.
     */
    public function tombstoneItems(TenantRef $tenant, string $contactId, bool $activities, bool $nextActions): void;
}
