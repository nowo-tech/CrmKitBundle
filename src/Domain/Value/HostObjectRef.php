<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;
use function strpos;
use function substr;

/**
 * An object of the host (a project, a ticket…), referenced by type and id (contract section 6.5).
 */
final readonly class HostObjectRef
{
    /** Host type pattern (anchored, `/D`). */
    public const TYPE_PATTERN = '/\A[a-z][a-z0-9_.-]{0,63}\z/D';

    /**
     * @param string $type the host object type, e.g. `project`
     * @param string $id the host id, exact
     */
    public function __construct(public string $type, public string $id)
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException('A host object type is 1-64 characters of a-z, 0-9, ".", "_" or "-", starting with a letter.');
        }
        if (!ExactId::isValid($id)) {
            throw new InvalidArgumentException('A host object id must be 1-128 bytes of UTF-8 without control, format or edge whitespace characters.');
        }
    }

    /**
     * The key of the object in batch results: `<type>:<id>`, e.g. `issue:42`. The type never holds a `:`, so the
     * first `:` splits the key even when the id contains one.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * The object of a {@see self::key()} (0.7.0, the link picker's value), or null when the key is not one. The id is
     * taken byte for byte after the first `:`.
     */
    public static function fromKey(string $key): ?self
    {
        $colon = strpos($key, ':');
        if ($colon === false) {
            return null;
        }
        try {
            return new self(substr($key, 0, $colon), substr($key, $colon + 1));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
