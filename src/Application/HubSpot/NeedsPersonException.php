<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use RuntimeException;

/**
 * An unattended HubSpot run met a record it may not write on its own (0.10.0, audit ZAM-1, the CSV rule UP-M-2 /
 * UP2-1): under `security.resource_scoping`, a record owned by someone else than the person who confirmed the import.
 * The page's unit rolls back and the import waits for a person's "Continue", which checks each record with that
 * person's access.
 *
 * @internal
 */
final class NeedsPersonException extends RuntimeException
{
    /** The pause reason. */
    public const CODE = 'hubspot_needs_person';

    /**
     * A constant message.
     */
    public function __construct()
    {
        parent::__construct('A person must continue this import.');
    }
}
