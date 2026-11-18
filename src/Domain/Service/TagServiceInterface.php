<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * Tags of the tenant (contract section 7): administration needs `manage`, assigning needs `edit` on the record.
 */
interface TagServiceInterface
{
    /**
     * Creates a tag; its id.
     */
    public function create(string $name, string $colour): string;

    /**
     * Renames / recolours a tag shown at `$expectedVersion`.
     */
    public function change(string $id, string $name, string $colour, int $expectedVersion): void;

    /**
     * Deletes a tag and its taggings.
     */
    public function delete(string $id): void;

    /**
     * Sets the tags of a record (set semantics, idempotent).
     *
     * @param list<string> $tagIds
     */
    public function assign(SubjectType $type, string $id, array $tagIds): void;
}
