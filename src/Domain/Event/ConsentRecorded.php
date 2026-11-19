<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A consent decision was recorded for a contact.
 */
final class ConsentRecorded extends CrmEvent
{
    /**
     * @param string $contactId the contact
     * @param string $purpose the purpose key
     * @param bool $granted granted or withdrawn
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly string $contactId,
        public readonly string $purpose,
        public readonly bool $granted,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
