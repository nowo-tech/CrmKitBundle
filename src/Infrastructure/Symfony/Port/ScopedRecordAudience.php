<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmRecordAudienceInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The default record audience (0.11.0), the default visibility rule for people other than the one signed in: without
 * `security.resource_scoping` every member of the tenant may view every record; with it, a record with an owner is
 * open to its owner only (the `settings_roles` people cannot be told apart without their roles, so they are left out),
 * a record without an owner to every member. A host with `nowo_crm_kit.record_access_check` listeners or its own
 * checker replaces it (`activities.audience`). Stateless.
 */
final readonly class ScopedRecordAudience implements CrmRecordAudienceInterface
{
    /**
     * @param bool $resourceScoping `security.resource_scoping`
     */
    public function __construct(private bool $resourceScoping = false)
    {
    }

    /**
     * The candidates allowed by the rule.
     */
    public function viewers(TenantRef $tenant, SubjectRef $subject, array $candidates): array
    {
        if (!$this->resourceScoping || !$subject->owner instanceof ActorRef) {
            return $candidates;
        }
        $out = [];
        foreach ($candidates as $candidate) {
            if ($candidate->value === $subject->owner->value) {
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
