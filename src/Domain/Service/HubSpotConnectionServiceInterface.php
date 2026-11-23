<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView;
use SensitiveParameter;

/**
 * The tenant's HubSpot connection (0.10.0, `import.hubspot.enabled`): one private app token per tenant, validated
 * against HubSpot before it is saved, sealed at rest, shown only as the account and the token's last four characters.
 */
interface HubSpotConnectionServiceInterface
{
    /**
     * The tenant's connection (no secret in the view); null when not connected. `import`.
     */
    public function status(): ?HubSpotConnectionView;

    /**
     * Validates the token against HubSpot (account, read-only scopes), then seals and stores it in one unit, replacing
     * the tenant's connection under its version (null: there is none yet). `import` + `manage` (Q-H3).
     *
     * @throws InvalidInputException `token`: error.hubspot_token_invalid | error.hubspot_scope_missing |
     *                               error.hubspot_scope_not_read_only | error.hubspot_unavailable |
     *                               error.hubspot_account_changed (repeat with `$confirmAccountChange`); never
     *                               HubSpot's text
     * @throws ConflictException crm_record_stale (another manager saved first), crm_hubspot_rate_limited (too many
     *                           token checks for the tenant)
     */
    public function connect(#[SensitiveParameter] string $token, ?string $label, ?int $expectedVersion, bool $confirmAccountChange = false): HubSpotConnectionView;

    /**
     * Removes the connection and its sealed token; running HubSpot imports stop at their next page. `import` +
     * `manage`.
     *
     * @throws ConflictException crm_record_stale
     */
    public function disconnect(int $expectedVersion): void;
}
