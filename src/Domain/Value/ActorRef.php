<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * A person of the host (the actor of a call, or a record owner). Opaque, compared byte for byte.
 */
final readonly class ActorRef
{
    use OpaqueRefTrait;

    /**
     * What every reference to an anonymised person becomes (0.2.1). Reserved: never an actor, an owner or a visible
     * owner, whatever a host checker, resolver or listener returns (audit VD-1).
     */
    public const ANONYMISED = 'anonymised';

    /**
     * Whether an id is reserved by the CRM and may never act or own (the anonymised placeholder).
     */
    public static function isReserved(string $id): bool
    {
        return $id === self::ANONYMISED;
    }
}
