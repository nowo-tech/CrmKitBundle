<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\IdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default id generator: UUIDv7 (time ordered), lowercase.
 */
final readonly class SymfonyUuidGenerator implements IdGeneratorInterface
{
    /**
     * A new id.
     */
    public function new(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
