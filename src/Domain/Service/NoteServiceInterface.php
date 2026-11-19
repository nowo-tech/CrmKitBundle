<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * Notes on records (contract section 9): `edit`; others' notes need `manage`.
 */
interface NoteServiceInterface
{
    /**
     * Adds a note; its id.
     */
    public function add(SubjectType $type, string $subjectId, string $body, bool $pinned = false): string;

    /**
     * Edits a note shown at `$expectedVersion`.
     */
    public function edit(string $id, string $body, bool $pinned, int $expectedVersion): void;

    /**
     * Deletes a note.
     */
    public function delete(string $id): void;
}
