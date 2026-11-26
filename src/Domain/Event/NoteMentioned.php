<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Members were newly mentioned in a note (0.11.0), after the commit: only people of the same tenant who may view the
 * record (checked inside the write), never the author, never someone already mentioned before the edit. The kit's
 * listener hands them to {@see CrmNotifierInterface}.
 */
final class NoteMentioned extends CrmEvent
{
    /**
     * @param list<ActorRef> $mentioned
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $subjectId,
        public readonly string $noteId,
        public readonly array $mentioned,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
