<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures\HubSpot;

use Nowo\CrmKitBundle\Domain\Port\CrmActorEmailLookupInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A host's e-mail lookup (0.10.0 tests): answers one asked address, one never asked (dropped by the kit) and the
 * anonymised placeholder (dropped too).
 */
final class TestEmailLookup implements CrmActorEmailLookupInterface
{
    public function actorsByEmails(TenantRef $tenant, array $emails): array
    {
        $found = ['intruder@x.example' => new ActorRef('mallory')];
        foreach ($emails as $email) {
            if ($email->key() === 'zoe@owners.example') {
                $found[$email->key()] = new ActorRef('zoe');
            }
            if ($email->key() === 'ghost@owners.example') {
                $found[$email->key()] = new ActorRef(ActorRef::ANONYMISED);
            }
        }

        return $found;
    }
}
