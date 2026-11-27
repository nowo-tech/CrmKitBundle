<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A person sent an e-mail to a contact from the CRM (0.13.0, *Write e-mail*), after the sender accepted it. Ids only:
 * no address, subject or text.
 */
final class CrmMailSent extends CrmEvent
{
    /**
     * @param string $contactId the recipient
     * @param string|null $engagementId the `email` activity logged for it (null when logging it failed)
     * @param string|null $templateId the MailKit template inserted, or null
     * @param string|null $purpose the template's consent purpose (null: a service message)
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly string $contactId,
        public readonly ?string $engagementId,
        public readonly ?string $templateId,
        public readonly ?string $purpose,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
