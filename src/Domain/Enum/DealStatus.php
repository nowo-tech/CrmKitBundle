<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * The status of a deal (0.3.0): open, won or lost (from the kind of its stage); trashed while in the trash
 * (restorable for `trash_days`, then purged).
 */
enum DealStatus: string
{
    case Open    = 'open';
    case Won     = 'won';
    case Lost    = 'lost';
    case Trashed = 'trashed';

    /**
     * The status of a deal in a stage of this kind.
     */
    public static function of(StageKind $kind): self
    {
        return match ($kind) {
            StageKind::Open => self::Open,
            StageKind::Won  => self::Won,
            StageKind::Lost => self::Lost,
        };
    }
}
