<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * Source of an import: a CSV or vCard file, or **(0.10.0)** a HubSpot account (`import.hubspot.enabled`).
 */
enum ImportFormat: string
{
    case Csv     = 'csv';
    case Vcard   = 'vcard';
    case HubSpot = 'hubspot';

    /**
     * Whether the import reads an uploaded file (CSV, vCard) rather than a connected source.
     */
    public function isFile(): bool
    {
        return $this !== self::HubSpot;
    }
}
