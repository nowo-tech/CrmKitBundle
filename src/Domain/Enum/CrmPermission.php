<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * What an actor may do in the CRM of a tenant (contract section 13). The access checker decides (deny by default).
 * **(0.2.0)** `Create`, `Delete`, `Import`, `Export` and `Erase` split the former `Edit` / `Manage` checks into the
 * areas of the house access checker (`CrmKitAccessCheckerInterface`); a host checker of 0.1.0 configured through the
 * deprecated root `access_checker` key gets them mapped back (Create / Delete / Import ⇒ Edit, Export / Erase ⇒ Manage).
 */
enum CrmPermission: string
{
    case View          = 'view';
    case Edit          = 'edit';
    case Manage        = 'manage';
    case AmountsView   = 'amounts_view';
    case CampaignsSend = 'campaigns_send';
    case Create        = 'create';
    case Delete        = 'delete';
    case Import        = 'import';
    case Export        = 'export';
    case Erase         = 'erase';

    /**
     * The permission a 0.1.0 checker knew for this one (Create / Delete / Import ⇒ Edit, Export / Erase ⇒ Manage).
     */
    public function legacy(): self
    {
        return match ($this) {
            self::Create, self::Delete, self::Import => self::Edit,
            self::Export, self::Erase                => self::Manage,
            default                                  => $this,
        };
    }
}
