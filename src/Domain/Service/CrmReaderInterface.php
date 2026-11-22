<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\ListResult;
use Nowo\CrmKitBundle\Domain\Read\NoteHit;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

/**
 * Reads of the CRM (contract section 7), all checked against `view` in the current tenant.
 */
interface CrmReaderInterface
{
    /**
     * One record (a merged id is answered with the merged tombstone: its `mergedIntoId` names the survivor).
     */
    public function record(SubjectType $type, string $id): RecordView;

    /**
     * A page of records.
     */
    public function list(ListQuery $query): ListResult;

    /**
     * Timeline entries of a record (a company includes its contacts'), newest first.
     *
     * @return list<ActivityView>
     */
    public function timeline(SubjectType $type, string $id, int $limit = 50, ?string $before = null): array;

    /**
     * Possible duplicates of a record (hints only).
     *
     * @return list<RecordView>
     */
    public function duplicates(SubjectType $type, string $id): array;

    /**
     * Notes of a record, pinned first.
     *
     * @return list<NoteView>
     */
    public function notes(SubjectType $type, string $id): array;

    /**
     * Notes of the tenant's active companies and contacts that contain the text, case-insensitively, newest first
     * (0.5.0). Every note's record passes the same view check as {@see self::notes()} (and the owner scoping of the
     * lists) before it counts towards `$limit`, so a hidden record shows nowhere. The text is trimmed and cut to
     * 200 characters; under 2 characters nothing is searched. `$limit` is clamped to 1–50.
     *
     * @return list<NoteHit>
     */
    public function searchNotes(string $query, int $limit = 20): array;

    /**
     * The notes of several records at once, pinned first, then newest first, at most `$perSubject` each (clamped to
     * 1–50) (0.5.0): a fixed number of queries per 100 records, never one per record. Keyed by
     * {@see SubjectRef::key()}; a record that is unknown, erased, merged or that the actor may not view (the same
     * check as {@see self::notes()}, plus the owner scoping of the lists) is left out, with no hint either way.
     *
     * @param list<SubjectRef> $subjects companies and contacts (anything else is ignored)
     *
     * @return array<string, list<NoteView>>
     */
    public function notesOf(array $subjects, int $perSubject = 5): array;

    /**
     * Host links of a record, resolved by the host (unresolved links are hidden).
     *
     * @return list<LinkView>
     */
    public function links(SubjectType $type, string $id): array;

    /**
     * Host panels of a record's 360° page.
     *
     * @return list<array{key: string, panel: Panel}>
     */
    public function panels(SubjectType $type, string $id): array;

    /**
     * Consent history of a contact, newest first.
     *
     * @return list<ConsentView>
     */
    public function consents(string $contactId): array;

    /**
     * The tenant's tags.
     *
     * @return list<TagView>
     */
    public function tags(): array;

    /**
     * Custom field definitions.
     *
     * @return list<FieldView>
     */
    public function fields(SubjectType $type, bool $withArchived = false): array;

    /**
     * People who may own records.
     *
     * @return list<ActorProfile>
     */
    public function owners(): array;
}
