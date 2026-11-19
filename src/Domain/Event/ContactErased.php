<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A contact's personal data was erased (GDPR).
 */
final class ContactErased extends CrmEvent
{
    /**
     * @param string $contactId the contact
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly string $contactId,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
