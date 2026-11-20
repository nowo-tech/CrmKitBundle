<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\ExactId;

use function preg_match;

/**
 * Who calls the API (0.3.0): the host's security user (handed to the area checker and the record events as is), the
 * CRM actor, the grant, the reach and the token. Built by the host on every request (an MCP authenticator, or
 * `CrmApiAuthenticatorInterface` for HTTP); never cached by the bundle.
 */
final readonly class CrmApiPrincipal
{
    public const CHANNEL_API = 'api';

    public const CHANNEL_MCP = 'mcp';

    /**
     * @param object $user the host's security user
     * @param ActorRef $actor the CRM actor (owner ids, created_by, audit)
     * @param string $tokenId opaque token id (never the secret), ExactId rule
     * @param string $tokenName the token's display name for the audit (sanitised by {@see AuditOrigin})
     * @param string $channel `[a-z][a-z0-9_]{0,31}`, set by the call path (in-process MCP ⇒ `mcp`), never by the client
     * @param object|null $hostContext opaque; handed to host ports that must apply the same token (`CrmTaskPort`)
     *
     * @throws InvalidArgumentException when the token id or the channel is malformed, or the actor is reserved
     */
    public function __construct(
        public object $user,
        public ActorRef $actor,
        public CrmApiGrant $grant,
        public CrmApiReach $reach,
        public string $tokenId,
        public string $tokenName,
        public string $channel = self::CHANNEL_API,
        public ?object $hostContext = null,
    ) {
        if (!ExactId::isValid($tokenId) || preg_match(AuditOrigin::CHANNEL_PATTERN, $channel) !== 1 || ActorRef::isReserved($actor->value)) {
            throw new InvalidArgumentException('Invalid API principal.');
        }
    }

    /**
     * The audit origin of the principal's writes.
     */
    public function origin(): AuditOrigin
    {
        return new AuditOrigin($this->channel, $this->tokenId, $this->tokenName);
    }
}
