<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A member who may be mentioned in a note of a record (0.11.0): the handle written as `@<handle>` and the name shown
 * in the suggestion list.
 */
final readonly class MentionCandidate
{
    /**
     * Wires the values.
     */
    public function __construct(public string $handle, public string $name)
    {
    }
}
