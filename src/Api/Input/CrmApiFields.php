<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function array_diff;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;

/**
 * Shape checks of the API's input arrays (0.3.0): unknown keys and wrong types are 400 `INPUT_002` on the field, a
 * missing required key is 400 `INPUT_001`. The CRM's own rules (lengths of record fields, e-mail syntax, …) are the
 * services' (422 `INPUT_004`). Never echoes the value. Stateless.
 *
 * @internal
 */
final class CrmApiFields
{
    /**
     * Refuses keys outside `$allowed`.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $allowed
     */
    public static function only(array $data, array $allowed, string $prefix = ''): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            throw CrmApiRefusal::malformed($prefix . (is_string($key) ? $key : '_'));
        }
    }

    /**
     * A string (valid UTF-8, at most `$max` characters), null when absent or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function text(array $data, string $key, int $max): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('//u', $value) !== 1 || mb_strlen($value) > $max) {
            throw CrmApiRefusal::malformed($key);
        }

        return $value;
    }

    /**
     * A record id (canonical lowercase UUID), null when absent or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function id(array $data, string $key): ?RecordId
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !RecordId::isValid($value)) {
            throw CrmApiRefusal::malformed($key);
        }

        return new RecordId($value);
    }

    /**
     * A required record id.
     *
     * @param array<array-key, mixed> $data
     */
    public static function requiredId(array $data, string $key): RecordId
    {
        return self::id($data, $key) ?? throw CrmApiRefusal::missing($key);
    }

    /**
     * A required string.
     *
     * @param array<array-key, mixed> $data
     */
    public static function requiredText(array $data, string $key, int $max): string
    {
        return self::text($data, $key, $max) ?? throw CrmApiRefusal::missing($key);
    }

    /**
     * An actor id (ExactId rule, never the reserved placeholder), null when absent or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function actor(array $data, string $key): ?ActorRef
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !ExactId::isValid($value) || ActorRef::isReserved($value)) {
            throw CrmApiRefusal::malformed($key);
        }

        return new ActorRef($value);
    }

    /**
     * A boolean, null when absent or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw CrmApiRefusal::malformed($key);
        }

        return $value;
    }

    /**
     * An integer in a range, null when absent or null.
     *
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key, int $min, int $max): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw CrmApiRefusal::malformed($key);
        }

        return $value;
    }

    /**
     * A list of strings matching a pattern (distinct), null when absent or null.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>|null
     */
    public static function keys(array $data, string $key, string $pattern, int $max = 50): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw CrmApiRefusal::malformed($key);
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1 || in_array($item, $out, true)) {
                throw CrmApiRefusal::malformed($key);
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * A list of distinct record ids, null when absent or null.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>|null
     */
    public static function ids(array $data, string $key, int $max = 50): ?array
    {
        return self::keys($data, $key, RecordId::PATTERN, $max);
    }

    /**
     * A nested object, null when absent or null.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw CrmApiRefusal::malformed($key);
        }
        $object = [];
        foreach ($value as $name => $item) {
            $object[(string) $name] = $item;
        }

        return $object;
    }

    /**
     * Whether the key is present (even with a null value).
     *
     * @param array<array-key, mixed> $data
     */
    public static function has(array $data, string $key): bool
    {
        return array_key_exists($key, $data);
    }
}
