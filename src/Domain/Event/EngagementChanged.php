<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A logged activity was changed (0.11.0).
 */
final class EngagementChanged extends CrmEvent
{
    /**
     * @param SubjectType $type the primary record's type
     * @param string $subjectId the primary record
     * @param string $engagementId the activity
     * @param ActivityKind $kind `call`, `meeting` or `email`
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $subjectId,
        public readonly string $engagementId,
        public readonly ActivityKind $kind,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
