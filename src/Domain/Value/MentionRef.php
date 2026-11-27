<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A member mentioned in a note (0.11.0): the person and the handle that stands for them in the text (`@<handle>`).
 * Handles are derived per tenant from a keyed hash of the actor id ({@see self::PATTERN}): opaque, stable, never the
 * id itself (so no e-mail address or internal id lands in the text), and meaningless in another tenant.
 */
final readonly class MentionRef
{
    /** A handle: `u` and 24 lowercase hexadecimal characters (a subset of MilkdownEditor's handle grammar). */
    public const PATTERN = '/\Au[0-9a-f]{24}\z/D';

    /**
     * @throws InvalidArgumentException a malformed handle
     */
    public function __construct(public ActorRef $actor, public string $handle)
    {
        if (preg_match(self::PATTERN, $handle) !== 1) {
            throw new InvalidArgumentException('Invalid mention handle.');
        }
    }
}
