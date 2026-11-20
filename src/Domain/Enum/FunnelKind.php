<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * The kind of a funnel (0.3.0): deals move through a deal funnel; companies or contacts through a party lifecycle
 * funnel (lead → prospect → customer → …).
 */
enum FunnelKind: string
{
    case Deal  = 'deal';
    case Party = 'party';
}
