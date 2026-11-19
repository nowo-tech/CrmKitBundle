<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;

use function array_filter;
use function array_values;

/**
 * Which operations this installation offers (0.3.0): the funnel operations only with `pipeline.enabled`. An MCP host
 * hides the tools that cannot work here; per-tenant decisions are still made on each call. Stateless.
 */
final readonly class CrmApiOperations
{
    /**
     * @param bool $pipeline `pipeline.enabled`
     */
    public function __construct(private bool $pipeline = false)
    {
    }

    /**
     * Whether the operation can work on this installation.
     */
    public function available(CrmApiOperation $operation): bool
    {
        return $this->pipeline || !$operation->pipeline();
    }

    /**
     * The available operations.
     *
     * @return list<CrmApiOperation>
     */
    public function all(): array
    {
        return array_values(array_filter(CrmApiOperation::cases(), $this->available(...)));
    }

    /**
     * Refuses an unavailable operation as out of reach (404 `ACCESS_001`).
     */
    public function require(CrmApiOperation $operation): void
    {
        if (!$this->available($operation)) {
            throw CrmApiRefusal::notFound();
        }
    }
}
