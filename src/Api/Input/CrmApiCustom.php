<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Value\FieldKey;

use function count;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;

/**
 * Custom field values of an input (0.3.0): `custom` is an object of field key => scalar or null (at most 200 keys,
 * strings ≤ 5 000 characters); the services check them against the tenant's definitions.
 *
 * @internal
 */
final class CrmApiCustom
{
    private function __construct()
    {
    }

    /**
     * The custom values of an input array, or null when absent.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, bool|float|int|string|null>|null
     */
    public static function parse(array $data): ?array
    {
        $custom = CrmApiFields::object($data, 'custom');
        if ($custom === null) {
            return null;
        }
        if (count($custom) > 200) {
            throw CrmApiRefusal::malformed('custom');
        }
        $out = [];
        foreach ($custom as $key => $value) {
            $ok = is_string($key) && preg_match(FieldKey::PATTERN, $key) === 1 && ($value === null || is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && preg_match('//u', $value) === 1 && mb_strlen($value) <= 5000));
            if (!$ok) {
                throw CrmApiRefusal::malformed('custom');
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
