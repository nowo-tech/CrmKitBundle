<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A company or contact, with its owner when known (access checks may narrow a grant to own records).
 */
final readonly class SubjectRef
{
    /**
     * @param SubjectType $type the kind of record
     * @param RecordId $id the record
     * @param ActorRef|null $owner the record's owner, when known
     */
    public function __construct(public SubjectType $type, public RecordId $id, public ?ActorRef $owner = null)
    {
    }

    /**
     * A reference from a type and an id string.
     */
    public static function of(SubjectType $type, string $id, ?string $owner = null): self
    {
        return new self($type, new RecordId($id), $owner === null ? null : new ActorRef($owner));
    }
}
