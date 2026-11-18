<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * A person of the host (the actor of a call, or a record owner). Opaque, compared byte for byte.
 */
final readonly class ActorRef
{
    use OpaqueRefTrait;
}
