<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Who, among members of the tenant, may view a record (0.11.0, `activities.audience`): the @mention candidates of a
 * note and the people a mention may notify. The kit keeps only returned refs that are among the candidates (`===`).
 * The default follows `security.resource_scoping` (off: every member; on: the record's owner, or every member when it
 * has none). A host with `nowo_crm_kit.record_access_check` listeners or its own checker implements it with the same
 * rule. Asked per call, never cached (worker mode).
 */
interface CrmRecordAudienceInterface
{
    /**
     * The candidates that may view the record.
     *
     * @param list<ActorRef> $candidates members of the tenant
     *
     * @return list<ActorRef>
     */
    public function viewers(TenantRef $tenant, SubjectRef $subject, array $candidates): array;
}
