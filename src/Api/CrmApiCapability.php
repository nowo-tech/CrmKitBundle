<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;

/**
 * What an operation asks of the grant (0.3.0, design §4.3). The person's own permission is asked separately, by the
 * caller ({@see self::permission()}). Anything not listed is denied.
 */
enum CrmApiCapability: string
{
    case Read         = 'read';
    case ReadAmounts  = 'read_amounts';
    case ReadConsents = 'read_consents';
    case Create       = 'create';
    case Edit         = 'edit';
    case WriteAmounts = 'write_amounts';
    case Trash        = 'trash';
    case ManageReach  = 'manage_reach';

    /**
     * Whether the grant covers the capability.
     */
    public function allows(CrmApiGrant $grant): bool
    {
        return match ($this) {
            self::Read               => $grant->level->includes(CrmApiLevel::View),
            self::ReadAmounts        => $grant->level->includes(CrmApiLevel::View) && $grant->amounts,
            self::ReadConsents       => $grant->level->includes(CrmApiLevel::View) && $grant->consents,
            self::Create, self::Edit => $grant->level->includes(CrmApiLevel::Edit),
            self::WriteAmounts       => $grant->level->includes(CrmApiLevel::Edit) && $grant->amounts,
            self::Trash              => $grant->level->includes(CrmApiLevel::Edit) && $grant->destructive,
            self::ManageReach        => $grant->level === CrmApiLevel::Manage,
        };
    }

    /**
     * The person's permission behind the capability.
     */
    public function permission(): CrmPermission
    {
        return match ($this) {
            self::Read, self::ReadConsents        => CrmPermission::View,
            self::ReadAmounts, self::WriteAmounts => CrmPermission::AmountsView,
            self::Create                          => CrmPermission::Create,
            self::Edit                            => CrmPermission::Edit,
            self::Trash                           => CrmPermission::Delete,
            self::ManageReach                     => CrmPermission::Manage,
        };
    }

    /**
     * The capability a permission needs from the grant, or null when no API grant covers it (imports, exports,
     * erasure, campaigns: never exposed).
     */
    public static function forPermission(CrmPermission $permission): ?self
    {
        return match ($permission) {
            CrmPermission::View        => self::Read,
            CrmPermission::AmountsView => self::ReadAmounts,
            CrmPermission::Create      => self::Create,
            CrmPermission::Edit        => self::Edit,
            CrmPermission::Delete      => self::Trash,
            CrmPermission::Manage      => self::ManageReach,
            default                    => null,
        };
    }
}
