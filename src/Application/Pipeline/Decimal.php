<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function number_format;
use function preg_match;
use function rtrim;
use function str_pad;
use function strlen;
use function substr;

use const PHP_INT_MAX;
use const PHP_INT_MIN;
use const STR_PAD_LEFT;

/**
 * Exact decimal strings for amounts (0.3.0): never floats, no bcmath needed. Stateless.
 *
 * @internal
 */
final class Decimal
{
    /**
     * A database number (`12.5000`, `12.5`, `1.2E+3` from SQLite) as a plain decimal string, or null.
     */
    public static function of(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = number_format((float) $value, 6, '.', '');
        }
        if (!is_string($value) || preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $value) !== 1) {
            return is_string($value) && is_numeric($value) ? self::of((float) $value) : null;
        }

        return self::trim($value);
    }

    /**
     * `$a + $b` for amounts of at most 4 fraction digits, exact while the sum stays within ±9.2e14 (0.3.0: open values
     * of a record).
     */
    public static function add(string $a, string $b): string
    {
        $x = self::units($a);
        $y = self::units($b);
        if (($y > 0 && $x > PHP_INT_MAX - $y) || ($y < 0 && $x < PHP_INT_MIN - $y)) {
            return number_format(($x + $y) / 10000, 4, '.', '');
        }

        return self::shift((string) ($x + $y), 4);
    }

    /**
     * A decimal string in ten-thousandths.
     */
    private static function units(string $value): int
    {
        $negative         = $value !== '' && $value[0] === '-';
        $digits           = $negative ? substr($value, 1) : $value;
        [$int, $fraction] = preg_match('/\A([0-9]+)(?:\.([0-9]*))?\z/', $digits, $m) === 1 ? [$m[1], $m[2] ?? ''] : ['0', ''];
        $units            = (int) ($int . substr(str_pad($fraction, 4, '0'), 0, 4));

        return $negative ? -$units : $units;
    }

    /**
     * `$amount × $percent / 100`, exact (at most 6 fraction digits).
     */
    public static function percent(string $amount, int $percent): string
    {
        $negative         = $amount !== '' && $amount[0] === '-';
        $digits           = $negative ? substr($amount, 1) : $amount;
        [$int, $fraction] = preg_match('/\A([0-9]+)(?:\.([0-9]*))?\z/', $digits, $m) === 1 ? [$m[1], $m[2] ?? ''] : ['0', ''];
        $fraction         = substr(str_pad($fraction, 4, '0'), 0, 4);
        $product          = self::multiply($int . $fraction, $percent);

        return self::shift(($negative ? '-' : '') . $product, 6);
    }

    /**
     * Divides a decimal string by 10^`$places` exactly.
     */
    public static function divide(string $value, int $places): string
    {
        $negative         = $value !== '' && $value[0] === '-';
        $digits           = $negative ? substr($value, 1) : $value;
        [$int, $fraction] = preg_match('/\A([0-9]+)(?:\.([0-9]*))?\z/', $digits, $m) === 1 ? [$m[1], $m[2] ?? ''] : ['0', ''];

        return self::shift(($negative ? '-' : '') . $int . $fraction, strlen($fraction) + $places);
    }

    /**
     * An integer string with its last `$places` digits as the fraction.
     */
    private static function shift(string $integer, int $places): string
    {
        $negative = $integer !== '' && $integer[0] === '-';
        $digits   = str_pad($negative ? substr($integer, 1) : $integer, $places + 1, '0', STR_PAD_LEFT);
        $value    = substr($digits, 0, strlen($digits) - $places) . ($places > 0 ? '.' . substr($digits, -$places) : '');

        return self::trim(($negative ? '-' : '') . $value);
    }

    /**
     * Digit string × small integer.
     */
    private static function multiply(string $digits, int $factor): string
    {
        $carry  = 0;
        $result = '';
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $n      = ((int) $digits[$i]) * $factor + $carry;
            $result = ($n % 10) . $result;
            $carry  = intdiv($n, 10);
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry  = intdiv($carry, 10);
        }

        return $result === '' ? '0' : $result;
    }

    /**
     * No leading integer zeros, no trailing fraction zeros, no `-0`.
     */
    private static function trim(string $value): string
    {
        $negative = $value !== '' && $value[0] === '-';
        $digits   = $negative ? substr($value, 1) : $value;
        if (str_contains($digits, '.')) {
            $digits = rtrim(rtrim($digits, '0'), '.');
        }
        $digits = ltrim($digits, '0');
        $digits = $digits === '' || $digits[0] === '.' ? '0' . $digits : $digits;

        return $negative && $digits !== '0' ? '-' . $digits : $digits;
    }
}
