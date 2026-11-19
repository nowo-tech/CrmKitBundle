<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;

use function is_string;
use function mb_strtolower;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const PHP_URL_HOST;

/**
 * The domain key of a company (contract section 6.3): the host of its website, lowercase, without a leading `www.`.
 * One rule for the stored key and for the lookups ({@see CrmBatchLookupInterface}),
 * so a lookup key always matches the stored one.
 */
final class CompanyDomain
{
    /** Longest key in bytes (the `domain_key` column). */
    public const MAX_BYTES = 190;

    /**
     * The key of a stored website (`https://www.Acme.test/about` ⇒ `acme.test`), or null without a host.
     */
    public static function fromWebsite(?string $website): ?string
    {
        $host = $website === null ? null : parse_url($website, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = mb_strtolower($host, 'UTF-8');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The key of a domain or a URL given to a lookup (`Acme.test`, `www.acme.test`, `https://acme.test/x` ⇒
     * `acme.test`), or null when it has no usable host (empty, invalid UTF-8, whitespace or control characters inside,
     * longer than the column, digits only).
     */
    public static function key(string $domainOrUrl): ?string
    {
        $value = trim($domainOrUrl);
        if ($value === '' || preg_match('//u', $value) !== 1 || preg_match('/[\s\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }
        $key = self::fromWebsite(str_contains($value, '://') ? $value : 'http://' . $value);

        // A host of digits only is no domain (and would not stay a string key in PHP arrays).
        return $key === null || strlen($key) > self::MAX_BYTES || preg_match('/\A[0-9]+\z/D', $key) === 1 ? null : $key;
    }
}
