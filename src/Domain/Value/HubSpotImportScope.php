<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;

use function array_values;
use function count;
use function in_array;
use function str_starts_with;
use function substr;

/**
 * What a HubSpot import reads (0.10.0): the objects (at least one; contacts imply companies, which are read first so
 * that a contact's company resolves) and up to 50 lists whose members get a tag.
 */
final readonly class HubSpotImportScope
{
    /** Most lists per import. */
    public const MAX_LISTS = 50;

    /** @var list<HubSpotObject> in reading order (companies first) */
    public array $objects;

    /** @var list<HubSpotObjectId> */
    public array $lists;

    /**
     * @param list<HubSpotObject> $objects
     * @param list<HubSpotObjectId> $lists distinct list ids
     */
    public function __construct(array $objects, array $lists = [])
    {
        if ($objects === []) {
            throw new InvalidArgumentException('A HubSpot import reads at least one object.');
        }
        if (count($lists) > self::MAX_LISTS) {
            throw new InvalidArgumentException('A HubSpot import reads at most 50 lists.');
        }
        $ordered = [];
        foreach (HubSpotObject::cases() as $object) {
            if (in_array($object, $objects, true) || ($object === HubSpotObject::Company && in_array(HubSpotObject::Contact, $objects, true))) {
                $ordered[] = $object;
            }
        }
        $seen = [];
        foreach ($lists as $list) {
            if (isset($seen[$list->value])) {
                throw new InvalidArgumentException('A list is read once.');
            }
            $seen[$list->value] = true;
        }
        $this->objects = $ordered;
        $this->lists   = array_values($lists);
    }

    /**
     * Whether the object is read.
     */
    public function reads(HubSpotObject $object): bool
    {
        return in_array($object, $this->objects, true);
    }

    /**
     * The scope as the import's `columns`: the object values, then `list:<id>`.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [];
        foreach ($this->objects as $object) {
            $columns[] = $object->value;
        }
        foreach ($this->lists as $list) {
            $columns[] = 'list:' . $list->value;
        }

        return $columns;
    }

    /**
     * The scope stored as an import's `columns`.
     *
     * @param list<string> $columns
     *
     * @throws InvalidArgumentException
     */
    public static function fromColumns(array $columns): self
    {
        $objects = [];
        $lists   = [];
        foreach ($columns as $column) {
            if (str_starts_with($column, 'list:')) {
                $lists[] = new HubSpotObjectId(substr($column, 5));

                continue;
            }
            $objects[] = HubSpotObject::tryFrom($column) ?? throw new InvalidArgumentException('Unknown HubSpot object.');
        }

        return new self($objects, $lists);
    }
}
