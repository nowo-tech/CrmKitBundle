<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;

/**
 * A note.
 */
final readonly class NoteView
{
    /**
     * @param string $body plain text
     */
    public function __construct(
        public string $id,
        public string $body,
        public bool $pinned,
        public ?string $authorId,
        public ?string $authorName,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
