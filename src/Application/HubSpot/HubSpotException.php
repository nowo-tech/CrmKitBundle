<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use RuntimeException;

/**
 * HubSpot did not answer as expected (0.10.0). Carries a code and the HTTP status only: never HubSpot's message, the
 * body, the URL or the token (design section 9.3).
 *
 * @internal
 */
final class HubSpotException extends RuntimeException
{
    /** 401: the token is refused (revoked, expired, wrong). */
    public const TOKEN_INVALID = 'hubspot_token_invalid';

    /** 403: a scope is missing. */
    public const SCOPE_MISSING = 'hubspot_scope_missing';

    /** 429: the burst limit (`retryAfter` seconds) or the daily limit (`daily`). */
    public const RATE_LIMITED = 'hubspot_rate_limited';

    /** 5xx, a timeout or a transport failure. */
    public const UNAVAILABLE = 'hubspot_unavailable';

    /** 400 / 404 from the token-info endpoint: not a private app token HubSpot knows (a service key). */
    public const UNKNOWN_TOKEN = 'hubspot_unknown_token';

    /** A redirect, an oversized or malformed body, or a shape the kit does not expect. */
    public const BAD_RESPONSE = 'hubspot_bad_response';

    /**
     * @param string $errorCode one of the constants
     * @param int $status the HTTP status (0: no answer)
     * @param int|null $retryAfter seconds HubSpot asked to wait (429)
     * @param bool $daily the daily limit (429 with `policyName: DAILY`)
     */
    public function __construct(public readonly string $errorCode, public readonly int $status = 0, public readonly ?int $retryAfter = null, public readonly bool $daily = false)
    {
        parent::__construct('HubSpot: ' . $errorCode . ' (' . $status . ').');
    }

    /**
     * Whether waiting and asking again may succeed (429 within a burst window, 5xx, a timeout).
     */
    public function isTransient(): bool
    {
        return ($this->errorCode === self::RATE_LIMITED && !$this->daily) || $this->errorCode === self::UNAVAILABLE;
    }
}
