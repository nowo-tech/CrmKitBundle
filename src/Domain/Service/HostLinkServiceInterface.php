<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

/**
 * Links between records and host objects by id (contract section 6.5).
 */
interface HostLinkServiceInterface
{
    /**
     * Links a record to a host object (idempotent); the link id.
     */
    public function link(SubjectType $type, string $subjectId, HostObjectRef $host, ?string $label = null): string;

    /**
     * Removes a link.
     */
    public function unlink(string $linkId): void;

    /**
     * The active records linked to a host object in the current tenant ("companies of project 42"), `view`.
     *
     * @return list<SubjectRef>
     */
    public function subjectsLinkedTo(HostObjectRef $host): array;
}
