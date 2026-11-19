<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;

use function hash;
use function preg_match;

/**
 * The idempotency key of a write (0.3.0): 1–255 bytes of printable ASCII (`0x21–0x7E`); anything else is 400
 * `IDEMP_003`, and none at all is 428 `IDEMP_005` ({@see self::from()}).
 */
final readonly class IdempotencyKey
{
    /**
     * @throws CrmApiRefusal `IDEMP_003`
     */
    public function __construct(public string $value)
    {
        if (preg_match('/\A[\x21-\x7E]{1,255}\z/', $value) !== 1) {
            throw CrmApiRefusal::of(CrmApiCode::IDEMP_INVALID);
        }
    }

    /**
     * The key of a request (null or empty: 428 `IDEMP_005`).
     */
    public static function from(?string $value): self
    {
        return $value === null || $value === '' ? throw CrmApiRefusal::of(CrmApiCode::IDEMP_MISSING) : new self($value);
    }

    /**
     * SHA-256 of the key, hex.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
