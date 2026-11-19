<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function ltrim;
use function preg_match;
use function rtrim;
use function str_contains;
use function substr;

/**
 * An amount (0.3.0): a decimal string of at most 14 integer and 4 fraction digits (`decimal(18,4)`), never a float,
 * and an ISO 4217 currency code. Amounts are never summed across currencies.
 */
final readonly class Money
{
    /** Shape of an amount: optional minus, up to 14 integer digits, up to 4 fraction digits. */
    public const AMOUNT_PATTERN = '/\A-?(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?\z/';

    /** Shape of a currency: three upper-case letters. */
    public const CURRENCY_PATTERN = '/\A[A-Z]{3}\z/';

    /** The amount, normalised (no trailing fraction zeros, `-0` is `0`). */
    public string $amount;

    /**
     * @throws InvalidArgumentException when the amount or the currency is malformed
     */
    public function __construct(string $amount, public string $currency)
    {
        if (preg_match(self::AMOUNT_PATTERN, $amount) !== 1 || preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
            throw new InvalidArgumentException('Invalid money.');
        }
        $this->amount = self::normalise($amount);
    }

    /**
     * Whether the strings make a valid amount.
     */
    public static function isValid(string $amount, string $currency): bool
    {
        return preg_match(self::AMOUNT_PATTERN, $amount) === 1 && preg_match(self::CURRENCY_PATTERN, $currency) === 1;
    }

    /**
     * A decimal string without trailing fraction zeros (as the database returns `decimal(18,4)` as `12.5000`).
     */
    public static function normalise(string $amount): string
    {
        if (str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
        }
        $negative = $amount !== '' && $amount[0] === '-';
        $digits   = ltrim($negative ? substr($amount, 1) : $amount, '0');
        $digits   = $digits === '' || $digits[0] === '.' ? '0' . $digits : $digits;

        return $negative && $digits !== '0' ? '-' . $digits : $digits;
    }

    /**
     * Whether two amounts are the same money.
     */
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
