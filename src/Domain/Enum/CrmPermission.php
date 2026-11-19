<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * What an actor may do in the CRM of a tenant (contract section 13). The host's access checker decides; the default
 * one grants nothing until roles are mapped (deny by default).
 */
enum CrmPermission: string
{
    case View          = 'view';
    case Edit          = 'edit';
    case Manage        = 'manage';
    case AmountsView   = 'amounts_view';
    case CampaignsSend = 'campaigns_send';
}
