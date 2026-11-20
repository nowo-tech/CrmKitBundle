<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;

use function is_string;

/**
 * The owner of a new or changed record (0.3.0): the principal (`me`), nobody (`none`) or one actor of the directory.
 */
final readonly class CrmApiOwner
{
    private function __construct(public ?string $actor, public bool $me)
    {
    }

    /** The principal. */
    public static function me(): self
    {
        return new self(null, true);
    }

    /** Nobody. */
    public static function none(): self
    {
        return new self(null, false);
    }

    /** One actor (checked against the directory by the service). */
    public static function actor(ActorRef $actor): self
    {
        return new self($actor->value, false);
    }

    /**
     * `me`, `none` or an actor id.
     */
    public static function parse(mixed $value): self
    {
        return match (true) {
            $value === 'me'                                                                => self::me(),
            $value === 'none'                                                              => self::none(),
            is_string($value) && ExactId::isValid($value) && !ActorRef::isReserved($value) => self::actor(new ActorRef($value)),
            default                                                                        => throw CrmApiRefusal::malformed('owner'),
        };
    }

    /**
     * The owner id for a principal acting as `$principal` (null: nobody).
     */
    public function resolve(ActorRef $principal): ?string
    {
        return $this->me ? $principal->value : $this->actor;
    }

    /**
     * `me`, `none` or the actor id.
     */
    public function canonical(): string
    {
        return $this->me ? 'me' : ($this->actor ?? 'none');
    }
}
