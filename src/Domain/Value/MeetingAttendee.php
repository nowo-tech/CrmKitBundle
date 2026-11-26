<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * Who takes part in a meeting (0.11.0): a member of the tenant (an actor) or a contact of the CRM, never both.
 */
final readonly class MeetingAttendee
{
    /**
     * Use {@see self::member()} or {@see self::contact()}.
     */
    private function __construct(public ?ActorRef $actor, public ?RecordId $contact)
    {
    }

    /**
     * A member of the tenant.
     */
    public static function member(ActorRef $actor): self
    {
        return new self($actor, null);
    }

    /**
     * A contact of the CRM.
     */
    public static function contact(RecordId $contact): self
    {
        return new self(null, $contact);
    }

    /**
     * `actor:<id>` or `contact:<id>` (unique per meeting).
     */
    public function key(): string
    {
        return $this->actor instanceof ActorRef ? 'actor:' . $this->actor->value : 'contact:' . $this->contact?->value;
    }
}
