<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * The kind of a funnel stage (0.3.0): an open stage, or a terminal won / lost stage (moving a deal there closes it).
 */
enum StageKind: string
{
    case Open = 'open';
    case Won  = 'won';
    case Lost = 'lost';

    /**
     * Whether a deal in a stage of this kind is closed.
     */
    public function closes(): bool
    {
        return $this !== self::Open;
    }
}
