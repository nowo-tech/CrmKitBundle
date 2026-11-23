<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotReaderInterface;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Event\HubSpotConnected;
use Nowo\CrmKitBundle\Domain\Event\HubSpotDisconnected;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotTokenInfo;
use Nowo\CrmKitBundle\Domain\Service\HubSpotConnectionServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use SensitiveParameter;

use function array_diff;
use function array_values;
use function count;
use function mb_strlen;
use function trim;

/**
 * {@see HubSpotConnectionServiceInterface} (0.10.0, design sections 5, 8, 9.2 and 12.4): the credential (a HubSpot
 * service key, or a legacy private app token) is checked against HubSpot outside any transaction (after a per-tenant
 * attempt counter, T7), refused when it misses a read scope or (when HubSpot lists its scopes) holds a write scope, then sealed with the secret box under the context (purpose, tenant, connection id) and
 * stored in one unit under the connection row's lock and version; a racing first connect loses on the unique tenant
 * key (409 stale). The plaintext lives only in this call. Stateless.
 */
final readonly class HubSpotConnectionService implements HubSpotConnectionServiceInterface
{
    /**
     * The scopes a credential must hold for slice H1 (lists: `crm.lists.read`, checked when an import reads lists;
     * deals: H2). Associations need no scope of their own.
     */
    public const REQUIRED_SCOPES = [
        'crm.objects.companies.read',
        'crm.objects.contacts.read',
        'crm.objects.owners.read',
        'crm.schemas.companies.read',
        'crm.schemas.contacts.read',
    ];

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WriteSupport $support,
        private HubSpotStoreInterface $store,
        private HubSpotReaderInterface $reader,
        private CrmSecretBoxInterface $box,
        private int $connectLimit = 5,
        private int $connectInterval = 600,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function status(): ?HubSpotConnectionView
    {
        $context = $this->support->access->context(CrmPermission::Import);

        return $this->support->read(fn (): ?HubSpotConnectionView => $this->store->connection($context->tenant)?->view());
    }

    /**
     * {@inheritDoc}
     */
    public function connect(#[SensitiveParameter] string $token, ?string $label, ?int $expectedVersion, bool $confirmAccountChange = false): HubSpotConnectionView
    {
        $context = $this->support->access->context(CrmPermission::Import);
        $this->support->access->require($context, CrmPermission::Manage);
        $token = trim($token);
        if (!HubSpotToken::isValid($token)) {
            throw InvalidInputException::field('token', 'error.hubspot_token_invalid');
        }
        $label = $label === null || trim($label) === '' ? null : trim($label);
        if ($label !== null && (mb_strlen($label) > 80 || !ExactId::isValid($label, 320))) {
            throw InvalidInputException::field('label', 'error.invalid');
        }
        $secret = new HubSpotToken($token);
        // T7: every check of a token counts, valid or not, in its own unit (committed before HubSpot is asked).
        if (!$this->support->write(fn (): bool => $this->store->takeConnectAttempt($context->tenant, $context->now, $this->connectLimit, $this->connectInterval))) {
            throw new ConflictException(ConflictException::HUBSPOT_RATE_LIMITED);
        }
        $info = $this->check($secret);

        try {
            $this->support->write(function (Effects $effects) use ($context, $secret, $label, $expectedVersion, $confirmAccountChange, $info): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $current = $this->store->lockConnection($context->tenant);
                if (($current?->getVersion() ?? null) !== $expectedVersion) {
                    throw new ConflictException(ConflictException::STALE);
                }
                if ($current instanceof HubSpotConnection && !$current->getAccountId()->equals($info->accountId) && !$confirmAccountChange) {
                    throw InvalidInputException::field('token', 'error.hubspot_account_changed');
                }
                $id = $current?->getId() ?? $this->support->id();
                try {
                    $sealed = $this->box->seal($secret->reveal(), new SecretContext(SecretContext::HUBSPOT_TOKEN, $context->tenant, $id));
                } catch (SecretKeyUnavailableException) {
                    // 0.10.1 (audit ZAM-D2): the sealing key is misconfigured; the stored connection stays as it was.
                    throw InvalidInputException::field('token', 'error.' . HubSpotTokenOpener::KEY_UNAVAILABLE);
                }
                $name = $label ?? 'HubSpot ' . $info->accountId->value;
                if ($current instanceof HubSpotConnection) {
                    $current->replace($info->accountId, $name, $secret->last4(), $sealed, $info->scopes, $context->now, $context->actor->value, $info->introspected);
                    // Imports paused because HubSpot refused the old token run again.
                    $this->store->resumeForConnection($context->tenant, $id, $context->now);
                } else {
                    $this->support->store->add(new HubSpotConnection($id, $context->tenant->value, $info->accountId, $name, $secret->last4(), $sealed, $info->scopes, $context->now, $context->actor->value, $context->now, $info->introspected)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
                $replaced = $current instanceof HubSpotConnection;
                $effects->event(new HubSpotConnected($context->tenant, $context->actor, $context->now, $info->accountId, $replaced));
                $this->support->audit($effects, $context, $replaced ? 'crm.hubspot.replaced' : 'crm.hubspot.connected', null, $id, ['account' => $info->accountId->value, 'scopes' => count($info->scopes)]);
            });
        } catch (DuplicateRowException $e) {
            // Two first connects of the tenant at once: the unique tenant key let one in.
            throw new ConflictException(ConflictException::STALE, null, $e);
        }

        return $this->status() ?? throw new ConflictException(ConflictException::STALE);
    }

    /**
     * {@inheritDoc}
     */
    public function disconnect(int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Import);
        $this->support->access->require($context, CrmPermission::Manage);
        $this->support->write(function (Effects $effects) use ($context, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $current = $this->store->lockConnection($context->tenant);
            if (!$current instanceof HubSpotConnection || $current->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE);
            }
            $this->support->store->remove($current); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $effects->event(new HubSpotDisconnected($context->tenant, $context->actor, $context->now, $current->getAccountId()));
            $this->support->audit($effects, $context, 'crm.hubspot.disconnected', null, $current->getId(), ['account' => $current->getAccountId()->value]);
        });
    }

    /**
     * Asks HubSpot about the credential, the same way for a service key and a legacy private app token: the account
     * from the account-info endpoint, then the scopes from HubSpot's token info when it knows the credential (a
     * private app token: write scopes are refused, Q-H2), else by probing each scope's read endpoint with `limit=1`
     * (a 403 is a missing scope; a service key has no scope-listing endpoint). A missing required scope, or token info
     * of another account, is refused.
     *
     * @throws InvalidInputException
     */
    private function check(HubSpotToken $token): HubSpotTokenInfo
    {
        try {
            $account = $this->reader->accountId($token);

            try {
                $info = $this->reader->tokenInfo($token);
            } catch (HubSpotException $e) {
                // Only HubSpot's "not a private app token" answer leads to probing (a service key); any other failure
                // refuses the credential (audit ZAM-3: the write-scope check fails closed).
                if ($e->errorCode !== HubSpotException::UNKNOWN_TOKEN) {
                    throw $e;
                }
                $info = null;
            }
            if ($info instanceof HubSpotTokenInfo && !$info->accountId->equals($account)) {
                $this->audit('crm.hubspot.token_invalid', ['account_mismatch' => 1]);

                throw InvalidInputException::field('token', 'error.hubspot_token_invalid');
            }
            if (!$info instanceof HubSpotTokenInfo) {
                $granted = [];
                foreach ([...self::REQUIRED_SCOPES, HubSpotImportService::LISTS_SCOPE] as $scope) {
                    if ($this->reader->probe($token, $scope)) {
                        $granted[] = $scope;
                    }
                }
                $info = new HubSpotTokenInfo($account, $granted, false);
            }
        } catch (HubSpotException $e) {
            $this->audit('crm.hubspot.token_invalid', ['status' => $e->status]);

            throw InvalidInputException::field('token', $e->isTransient() ? 'error.hubspot_unavailable' : 'error.hubspot_token_invalid');
        }
        if ($info->writeScopes() !== []) {
            $this->audit('crm.hubspot.token_invalid', ['write_scopes' => count($info->writeScopes())]);

            throw InvalidInputException::field('token', 'error.hubspot_scope_not_read_only');
        }
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $info->scopes));
        if ($missing !== []) {
            $this->audit('crm.hubspot.token_invalid', ['missing_scopes' => count($missing)]);

            throw InvalidInputException::field('token', 'error.hubspot_scope_missing');
        }

        return $info;
    }

    /**
     * Audits a refused token (counts only).
     *
     * @param array<string, int> $details
     */
    private function audit(string $action, array $details): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $action, $details): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->audit($effects, $context, $action, null, null, $details);
        });
    }
}
