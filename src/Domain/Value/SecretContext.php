<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function in_array;

/**
 * What a sealed secret belongs to (0.10.0): its purpose, the tenant and the row. Bound into the ciphertext as
 * additional authenticated data ({@see self::aad()}), so a sealed value copied to another tenant, row or column does
 * not open.
 */
final readonly class SecretContext
{
    /** Purpose of a HubSpot private app token. */
    public const HUBSPOT_TOKEN = 'hubspot.token';

    /** Purposes the kit seals (H3 adds `hubspot.refresh_token`). */
    public const PURPOSES = [self::HUBSPOT_TOKEN];

    /**
     * @param string $purpose one of {@see self::PURPOSES}
     * @param string $rowId the id of the row that holds the sealed value
     */
    public function __construct(public string $purpose, public TenantRef $tenant, public string $rowId)
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Unknown secret purpose.');
        }
        if (!RecordId::isValid($rowId)) {
            throw new InvalidArgumentException('The row of a secret is a record id.');
        }
    }

    /**
     * The additional authenticated data: `nowo-crm-kit|v1|<purpose>|<tenant>|<row id>` (the tenant is an exact id and
     * holds no control character, so the parts cannot run into each other ambiguously at a fixed position).
     */
    public function aad(): string
    {
        return 'nowo-crm-kit|v1|' . $this->purpose . '|' . $this->tenant->value . '|' . $this->rowId;
    }
}
