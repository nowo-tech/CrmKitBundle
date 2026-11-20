<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function mb_substr;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Where a write came from when it was not the CRM's own pages (0.3.0): the channel (`api`, `mcp`, …), the token id
 * (opaque, never the secret) and the token's display name for the audit. The token name names a token, not a person:
 * it is the one documented exception to "never names" in {@see AuditEntry}. It is cut to 120 characters and loses its
 * control and format characters.
 */
final readonly class AuditOrigin
{
    /** Shape of a channel. */
    public const CHANNEL_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /** Longest token name kept, in characters. */
    public const MAX_NAME = 120;

    /** The token name. */
    public string $tokenName;

    /**
     * @param string $channel `api`, `mcp`, …
     * @param string $tokenId the token's id (ExactId rule)
     * @param string $tokenName the token's display name (sanitised here)
     *
     * @throws InvalidArgumentException when the channel or the token id is malformed
     */
    public function __construct(public string $channel, public string $tokenId, string $tokenName)
    {
        if (preg_match(self::CHANNEL_PATTERN, $channel) !== 1 || !ExactId::isValid($tokenId)) {
            throw new InvalidArgumentException('Invalid audit origin.');
        }
        $this->tokenName = self::clean($tokenName);
    }

    /**
     * A token name without control, format or separator characters, at most {@see self::MAX_NAME} characters; `token`
     * when nothing printable is left.
     */
    public static function clean(string $name): string
    {
        $name = preg_match('//u', $name) === 1 ? (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $name) : '';
        $name = trim(mb_substr(trim($name), 0, self::MAX_NAME));

        return $name === '' ? 'token' : $name;
    }
}
