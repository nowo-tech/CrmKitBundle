<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Opens a connection's sealed token for one call (0.10.0). A token that no longer opens (key lost or rotated away,
 * tampering) marks the connection invalid in its own unit and answers null, so a manager is asked for a new token and
 * no run loops on it (design T14). The plaintext is never kept: the caller uses the returned token for one HTTP call.
 *
 * @internal
 */
final readonly class HubSpotTokenOpener
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmSecretBoxInterface $box, private HubSpotStoreInterface $store, private TransactionRunnerInterface $runner)
    {
    }

    /**
     * The connection's token, or null when it does not open (the connection is then invalid).
     */
    public function open(TenantRef $tenant, HubSpotConnection $connection, DateTimeImmutable $now): ?HubSpotToken
    {
        try {
            return new HubSpotToken($this->box->unseal($connection->getToken(), new SecretContext(SecretContext::HUBSPOT_TOKEN, $tenant, $connection->getId())));
        } catch (SecretUnsealException|InvalidArgumentException) {
            $this->runner->write(function () use ($tenant, $connection, $now): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $this->store->invalidate($tenant, $connection->getId(), $now);
            });

            return null;
        }
    }
}
