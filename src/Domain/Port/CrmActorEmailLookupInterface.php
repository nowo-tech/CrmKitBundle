<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Optional host port (0.10.0): the actors of a tenant who may own records, by e-mail (the HubSpot import turns
 * HubSpot owners into CRM owners). Without it the kit filters `CrmActorDirectoryInterface::owners()` by e-mail. The
 * kit re-checks every returned key with `===` against the requested ones and drops any other (REQ-SEC-006 rule 4).
 */
interface CrmActorEmailLookupInterface
{
    /**
     * The actors of the tenant whose e-mail is one of the given ones, keyed by {@see EmailAddress::key()}.
     *
     * @param list<EmailAddress> $emails
     *
     * @return array<string, ActorRef>
     */
    public function actorsByEmails(TenantRef $tenant, array $emails): array;
}
