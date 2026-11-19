<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine\Type;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

use function is_string;

/**
 * A UTC instant **to the microsecond** (as {@see UtcDateTimeImmutableType}, with the fraction kept): the edit time of
 * versioned rows, so every write changes the stored value and Doctrine's optimistic version always moves (owner
 * decision 2026-11-19). The column is `DATETIME(6)` on MySQL / MariaDB and `TIMESTAMP(6) WITHOUT TIME ZONE` on
 * PostgreSQL, declared by `CrmMetadataListener` as the column definition (the type keeps the plain declaration, so
 * schema comparisons stay quiet); SQLite keeps the text.
 */
final class UtcMicroDateTimeImmutableType extends DateTimeImmutableType
{
    /** Type name used by the mapping. */
    public const NAME = 'nowo_crm_utc_datetime_micro';

    /** Format of the stored value. */
    public const FORMAT = 'Y-m-d H:i:s.u';

    /**
     * The UTC wall clock of the instant, with microseconds.
     *
     * @param T $value
     *
     * @return (T is null ? null : string)
     *
     * @template T
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
        }

        // Null, or the parent's refusal of anything else (its exception on DBAL 3 and 4).
        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * The stored UTC wall clock as a UTC instant (with or without a fraction: PostgreSQL leaves out a zero one).
     *
     * @param T $value
     *
     * @return (T is null ? null : DateTimeImmutable)
     *
     * @template T
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value?->setTimezone(new DateTimeZone('UTC'));
        }
        $utc  = new DateTimeZone('UTC');
        $text = is_string($value) ? $value : '';
        foreach (['!' . self::FORMAT, '!Y-m-d H:i:s'] as $format) {
            $read = DateTimeImmutable::createFromFormat($format, $text, $utc);
            if ($read !== false) {
                return $read;
            }
        }

        return new DateTimeImmutable($text, $utc);
    }

    /**
     * The type name (DBAL 3).
     */
    public function getName(): string
    {
        return self::NAME;
    }
}
