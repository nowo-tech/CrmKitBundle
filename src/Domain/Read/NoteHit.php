<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;

/**
 * A note found by {@see CrmReaderInterface::searchNotes()} (0.5.0): the note, the
 * record it is on (by id) and the part of its text around the first match.
 */
final readonly class NoteHit
{
    /** Characters of note text an excerpt keeps. */
    public const EXCERPT_LENGTH = 200;

    /**
     * @param SubjectType $subjectType company or contact
     * @param string $subjectId the record's id
     * @param NoteView $note the note, as the record's notes show it
     * @param string $excerpt plain text around the first match (at most {@see self::EXCERPT_LENGTH} characters plus
     *                        an ellipsis on each cut side), or the start of the note
     */
    public function __construct(
        public SubjectType $subjectType,
        public string $subjectId,
        public NoteView $note,
        public string $excerpt,
    ) {
    }
}
