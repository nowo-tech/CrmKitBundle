<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\FeedKind;
use Nowo\CrmKitBundle\Domain\Value\HostActivityItem;

/**
 * One entry of a record's activity feed (0.11.0): a note, a logged activity or a host item, exactly one of them set.
 */
final readonly class FeedItem
{
    /**
     * @param string $id the note, activity or host item id (the keyset tie-breaker; host items are prefixed `host:`)
     * @param string|null $text a note's text with its mentions shown as names (plain text)
     */
    public function __construct(
        public FeedKind $kind,
        public string $id,
        public DateTimeImmutable $at,
        public ?string $actorId,
        public ?string $actorName,
        public ?NoteView $note = null,
        public ?EngagementView $engagement = null,
        public ?HostActivityItem $host = null,
        public ?string $text = null,
    ) {
    }
}
