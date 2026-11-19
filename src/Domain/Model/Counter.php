<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use Nowo\CrmKitBundle\Domain\Enum\CrmResource;

/**
 * A plan-limit counter (contract section 15.1). Mapped so the schema tool creates the table; only moved by atomic SQL.
 */
class Counter
{
    /**
     * @param string $resource a {@see CrmResource} value, or `__purge__`
     */
    public function __construct(private string $tenantId, private string $resource, private int $value)
    {
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** The resource. */
    public function getResource(): string
    {
        return $this->resource;
    }

    /** The count. */
    public function getValue(): int
    {
        return $this->value;
    }
}
