<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;

use function str_ends_with;

/**
 * What HubSpot says about a credential (0.10.0): its account and the scopes it was seen to hold. `introspected`: the
 * scopes are HubSpot's own list (a legacy private app token answers the token-info endpoint), so write scopes are
 * seen too; false: the scopes were found by probing each required read endpoint (a service key), which cannot see
 * write scopes. Never the credential.
 */
final readonly class HubSpotTokenInfo
{
    /** A scope entry the kit could not read (counted as a write scope: the check fails closed). */
    public const UNREADABLE = '!unreadable';

    /**
     * @param list<string> $scopes the token's scopes as HubSpot lists them
     */
    public function __construct(public HubSpotAccountId $accountId, public array $scopes, public bool $introspected = true)
    {
    }

    /**
     * The scopes that are not read-only (anything not ending in `.read`, except `oauth`): a token holding one is
     * refused (Q-H2), so a stolen sealed token cannot write to the customer's HubSpot.
     *
     * @return list<string>
     */
    public function writeScopes(): array
    {
        $write = [];
        foreach ($this->scopes as $scope) {
            if ($scope !== 'oauth' && !str_ends_with($scope, '.read')) {
                $write[] = $scope;
            }
        }

        return $write;
    }
}
