<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

/**
 * The level of an API grant (0.3.0): a chain, `view ⊂ edit ⊂ manage` (a Beacon token's `crm.view`, `crm.edit`,
 * `crm.manage`).
 */
enum CrmApiLevel: string
{
    case View   = 'view';
    case Edit   = 'edit';
    case Manage = 'manage';

    /**
     * Whether this level includes `$other`.
     */
    public function includes(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::View   => 1,
            self::Edit   => 2,
            self::Manage => 3,
        };
    }
}
