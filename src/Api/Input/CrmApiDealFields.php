<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;

use function is_string;
use function preg_match;
use function preg_replace;

/**
 * Shape checks of deal and next-action inputs (0.3.0): money, dates and instants. Stateless.
 *
 * @internal
 */
final class CrmApiDealFields
{
    private function __construct()
    {
    }

    /**
     * `amount` (decimal string) and `currency` (ISO 4217) together, or null when both are absent / null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function money(array $data): ?Money
    {
        $amount   = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        if ($amount === null && $currency === null) {
            return null;
        }
        if (!is_string($amount) || !is_string($currency) || !Money::isValid($amount, $currency)) {
            throw CrmApiRefusal::malformed(is_string($amount) && Money::isValid($amount, 'EUR') ? 'currency' : 'amount');
        }

        return new Money($amount, $currency);
    }

    /**
     * A `Y-m-d` date, or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function date(array $data, string $key): ?DateImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        try {
            return is_string($value) ? new DateImmutable($value) : throw CrmApiRefusal::malformed($key);
        } catch (InvalidArgumentException) {
            throw CrmApiRefusal::malformed($key);
        }
    }

    /**
     * An RFC 3339 instant with an offset (stored in UTC), or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function instant(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            throw CrmApiRefusal::malformed($key);
        }
        $normal = (string) preg_replace(['/Z\z/', '/(T\d{2}:\d{2}:\d{2})(?=[+-])/'], ['+00:00', '$1.0'], $value);
        $normal = (string) preg_replace('/(T\d{2}:\d{2}:\d{2})\+00:00\z/', '$1.0+00:00', $normal);
        $at     = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $normal);
        $errors = DateTimeImmutable::getLastErrors();
        if ($at === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw CrmApiRefusal::malformed($key);
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
