<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A note was pinned or unpinned (0.11.0); `unpinned` lists the notes the pin limit unpinned in the same write.
 */
final class NotePinned extends CrmEvent
{
    /**
     * @param list<string> $unpinned
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $subjectId,
        public readonly string $noteId,
        public readonly bool $pinned,
        public readonly array $unpinned = [],
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
