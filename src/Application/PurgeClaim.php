<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

/**
 * The single-run claim of one retention purge run (contract section 16.4): the unix time stored in the claim row
 * while this run holds it. A per-run holder created by {@see RecordPurger::purge()}, never service state.
 *
 * @internal
 */
final class PurgeClaim
{
    /**
     * @param int $value the claim time this run stored last
     */
    public function __construct(public int $value)
    {
    }
}
