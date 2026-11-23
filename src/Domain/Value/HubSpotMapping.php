<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;

use function array_key_exists;
use function explode;
use function in_array;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * The mapping of a HubSpot import (0.10.0): per object, HubSpot property => CRM target, and per list, the tag its
 * members get. Targets are those of a CSV column: a core field of the record type, `custom.<key>`, `owner` (the
 * `hubspot_owner_id` through the owners' e-mails), for companies `domain` (a bare domain that fills the website
 * when the website is empty), or `''` (skip). Each target once per object. A list's tag is an existing tag id or
 * {@see self::CREATE} (a tag named after the list, created when the import is confirmed).
 */
final readonly class HubSpotMapping
{
    /** A list whose tag is created on confirmation. */
    public const CREATE = 'create';

    /** Separator of the stored keys (`companies|name`, `list|123`); never part of a property name. */
    private const SEPARATOR = '|';

    /**
     * @param array<string, array<string, string>> $properties object value => (property => target)
     * @param array<string, string> $lists list id => tag id or {@see self::CREATE}
     */
    public function __construct(public array $properties, public array $lists = [])
    {
        foreach ($properties as $object => $map) {
            $kind = HubSpotObject::tryFrom((string) $object) ?? throw new InvalidArgumentException('Unknown HubSpot object.');
            $used = [];
            foreach ($map as $property => $target) {
                if (!HubSpotKey::isValid((string) $property) || !self::isTarget($kind, $target)) {
                    throw new InvalidArgumentException('Unknown HubSpot property or target.');
                }
                if ($target !== '' && isset($used[$target])) {
                    throw new InvalidArgumentException('A target is mapped once per object.');
                }
                $used[$target] = true;
            }
        }
        foreach ($lists as $list => $tag) {
            if (!HubSpotObjectId::isValid((string) $list) || ($tag !== self::CREATE && !RecordId::isValid($tag))) {
                throw new InvalidArgumentException('A list maps to a tag id or "create".');
            }
        }
    }

    /**
     * Whether the target is one an object's property may map to (custom keys are checked against the active fields
     * when the import is confirmed).
     */
    public static function isTarget(HubSpotObject $object, string $target): bool
    {
        if ($target === '' || $target === 'owner' || ($target === 'domain' && $object === HubSpotObject::Company)) {
            return true;
        }
        if (str_starts_with($target, 'custom.')) {
            return preg_match(FieldKey::PATTERN, substr($target, 7)) === 1;
        }

        return array_key_exists($target, RecordFields::of($object->subjectType()));
    }

    /**
     * The property => target map of an object (empty when it is not mapped).
     *
     * @return array<string, string>
     */
    public function of(HubSpotObject $object): array
    {
        return $this->properties[$object->value] ?? [];
    }

    /**
     * The properties of an object that map somewhere (what the reader asks HubSpot for: data minimisation).
     *
     * @return list<HubSpotKey>
     */
    public function requested(HubSpotObject $object): array
    {
        $keys = [];
        foreach ($this->of($object) as $property => $target) {
            if ($target !== '') {
                $keys[] = new HubSpotKey((string) $property);
            }
        }

        return $keys;
    }

    /**
     * Whether the object's `custom.<key>` targets are all among the given active keys.
     *
     * @param list<string> $customKeys
     */
    public function customKnown(HubSpotObject $object, array $customKeys): bool
    {
        foreach ($this->of($object) as $target) {
            if (str_starts_with($target, 'custom.') && !in_array(substr($target, 7), $customKeys, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The mapping as an import's flat `mapping` column.
     *
     * @return array<string, string>
     */
    public function flat(): array
    {
        $flat = [];
        foreach ($this->properties as $object => $map) {
            foreach ($map as $property => $target) {
                $flat[$object . self::SEPARATOR . $property] = $target;
            }
        }
        foreach ($this->lists as $list => $tag) {
            $flat['list' . self::SEPARATOR . $list] = $tag;
        }

        return $flat;
    }

    /**
     * The mapping stored as an import's flat `mapping` column.
     *
     * @param array<string, string> $flat
     *
     * @throws InvalidArgumentException
     */
    public static function fromFlat(array $flat): self
    {
        $properties = [];
        $lists      = [];
        foreach ($flat as $key => $target) {
            $key = (string) $key;
            if (!str_contains($key, self::SEPARATOR)) {
                throw new InvalidArgumentException('Not a HubSpot mapping key.');
            }
            [$scope, $name] = explode(self::SEPARATOR, $key, 2);
            if ($scope === 'list') {
                $lists[$name] = $target;
            } else {
                $properties[$scope][$name] = $target;
            }
        }

        return new self($properties, $lists);
    }
}
