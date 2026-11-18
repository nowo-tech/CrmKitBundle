<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A note was added to a record.
 */
final class NoteAdded extends CrmEvent
{
    /**
     * @param SubjectType $type the kind of record
     * @param string $subjectId the record
     * @param string $noteId the note
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $subjectId,
        public readonly string $noteId,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
