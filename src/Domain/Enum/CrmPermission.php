<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * What an actor may do in the CRM of a tenant (contract section 13). The access checker decides (deny by default).
 * **(0.2.0)** `Create`, `Delete`, `Import`, `Export` and `Erase` split the former `Edit` / `Manage` checks into the
 * areas of the house access checker (`CrmKitAccessCheckerInterface`). **(0.3.0)** The 0.1.0 root `access_checker` key
 * and its mapping back to the five old permissions (`legacy()`) are removed.
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
}
