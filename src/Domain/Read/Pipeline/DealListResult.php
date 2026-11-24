<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

/**
 * One numbered page of deals (0.10.2, the deals list of the UI: "Showing 1–25 of N" and page links): the deals and
 * the number of deals the query matches.
 */
final readonly class DealListResult
{
    /**
     * @param list<DealView> $deals
     * @param int $total all matching deals (the SQL count less the denied rows of this page)
     */
    public function __construct(public array $deals, public int $total)
    {
    }
}
