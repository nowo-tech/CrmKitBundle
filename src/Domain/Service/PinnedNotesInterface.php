<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

/**
 * Pins and unpins notes (0.11.0): `edit` on the record. With `activities.max_pinned` (default 1) pinning a note unpins
 * the oldest pinned notes of the record beyond the limit, in the same write.
 */
interface PinnedNotesInterface
{
    /**
     * Pins (`true`) or unpins a note shown at `$expectedVersion`.
     */
    public function pin(string $id, bool $pinned, int $expectedVersion): void;
}
