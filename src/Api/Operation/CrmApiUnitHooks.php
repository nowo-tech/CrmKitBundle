<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Closure;

/**
 * What runs first and last inside the write unit of one API write (0.3.0): the idempotency claim and the stored
 * outcome. Built per call, never kept.
 */
final readonly class CrmApiUnitHooks
{
    /**
     * @param Closure(): void $begin
     * @param Closure(mixed): void $end
     */
    public function __construct(private Closure $begin, private Closure $end)
    {
    }

    /**
     * Runs the first statement of the unit.
     */
    public function begin(): void
    {
        ($this->begin)();
    }

    /**
     * Runs the last statement of the unit.
     */
    public function end(mixed $result): void
    {
        ($this->end)($result);
    }
}
