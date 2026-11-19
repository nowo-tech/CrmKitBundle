<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * The tenant (organisation) a call belongs to, as the host identifies it. Opaque, compared byte for byte.
 */
final readonly class TenantRef
{
    use OpaqueRefTrait;

    /** Tenant of single-tenant hosts. */
    public const DEFAULT = 'default';

    /**
     * The tenant of a single-tenant host.
     */
    public static function default(): self
    {
        return new self(self::DEFAULT);
    }
}
