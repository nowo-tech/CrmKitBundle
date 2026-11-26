<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * One person of a logged meeting (0.11.0): a member (`actorId`) or a contact (`contactId`), with the name the viewer
 * may see.
 */
final readonly class AttendeeView
{
    /**
     * Wires the values.
     */
    public function __construct(public ?string $actorId, public ?string $contactId, public string $name)
    {
    }
}
