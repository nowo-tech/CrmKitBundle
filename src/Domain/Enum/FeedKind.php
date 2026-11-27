<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

use Nowo\CrmKitBundle\Domain\Port\CrmHostActivitySourceInterface;

/**
 * The kinds of the activity feed of a record (0.11.0), the values of its kind filter: notes, the logged calls,
 * meetings and e-mails, and the host's items ({@see CrmHostActivitySourceInterface}).
 */
enum FeedKind: string
{
    case Note    = 'note';
    case Call    = 'call';
    case Meeting = 'meeting';
    case Email   = 'email';
    case Host    = 'host';

    /**
     * The logged activity kind of a feed kind (null for notes and host items).
     */
    public function activityKind(): ?ActivityKind
    {
        return match ($this) {
            self::Call    => ActivityKind::Call,
            self::Meeting => ActivityKind::Meeting,
            self::Email   => ActivityKind::Email,
            default       => null,
        };
    }

    /**
     * The feed kind of a logged activity kind.
     */
    public static function ofActivity(ActivityKind $kind): ?self
    {
        return match ($kind) {
            ActivityKind::Call    => self::Call,
            ActivityKind::Meeting => self::Meeting,
            ActivityKind::Email   => self::Email,
            default               => null,
        };
    }
}
