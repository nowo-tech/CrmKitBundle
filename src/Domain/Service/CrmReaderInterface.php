<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\ListResult;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\Panel;

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
