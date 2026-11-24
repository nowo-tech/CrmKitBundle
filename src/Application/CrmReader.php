<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\ListResult;
use Nowo\CrmKitBundle\Domain\Read\NoteHit;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\PanelContext;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\TransactionContract\TransientStorageFailure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_chunk;
use function array_key_last;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_a;
use function max;
use function mb_stripos;
use function mb_strlen;
use function mb_substr;
use function min;
use function preg_match;
use function trim;

/**
 * The CRM's reads (contract section 7): every call resolves the tenant and checks `view` (on the record when there is
 * one). Panels and host links are asked from the host per call. Stateless.
 */
final readonly class CrmReader implements CrmReaderInterface
{
    /** Contacts of a company whose timeline entries the company page includes. */
    private const TIMELINE_CONTACTS = 200;

    /** Most hits of a note search. */
    public const NOTE_SEARCH_LIMIT = 50;

    /** Longest note search text (longer text is cut). */
    public const NOTE_QUERY_LENGTH = 200;

    /** Shortest note search text. */
    public const NOTE_QUERY_MIN = 2;

    /** Matching notes read per round of a note search, and most rounds (at most 500 notes are looked at). */
    private const NOTE_SEARCH_BATCH = 50;

    private const NOTE_SEARCH_ROUNDS = 10;

    /** Most notes per record of {@see self::notesOf()}. */
    public const NOTES_PER_SUBJECT = 50;

    /** Records per round of {@see self::notesOf()}. */
    private const NOTES_OF_CHUNK = 100;

    private LoggerInterface $logger;

    /**
     * @param iterable<CrmPanelProviderInterface> $panels
     * @param iterable<CrmHostObjectResolverInterface> $resolvers
     */
    public function __construct(
        private WriteSupport $support,
        private RecordViews $views,
        private CrmActorDirectoryInterface $directory,
        private iterable $panels = [],
        private iterable $resolvers = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * A copy bound to `$caller` for one call (0.3.0: an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->support->as($caller), $this->views, $this->directory, $this->panels, $this->resolvers, $this->logger);
    }

    /**
     * One record.
     */
    public function record(SubjectType $type, string $id): RecordView
    {
        return $this->support->read(function () use ($type, $id): RecordView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context, $record] = $this->load($type, $id);

            return $this->views->one($context->tenant, $record, $this->support->access->visibleOwners($context), $this->viewable($context));
        });
    }

    /**
     * A page of records.
     */
    public function list(ListQuery $query): ListResult
    {
        $context = $this->support->access->context(CrmPermission::View);

        // 0.10.2: "Mine" is the acting person's records.
        $query = $query->forActor($context->actor->value);

        return $this->support->read(function () use ($context, $query): ListResult { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$records, $total] = $this->support->store->list($context->tenant, $query, $this->support->access->visibleOwners($context));
            // 0.3.0: a record a nowo_crm_kit.record_access_check listener denies is left out of the page, and out of
            // the total as far as this page shows (the total is the SQL count less the denied rows of the page).
            $visible = $this->support->access->viewableRecords($context, $records);

            return new ListResult($this->views->many($context->tenant, $query->type, $visible), max(0, $total - (count($records) - count($visible))));
        });
    }

    /**
     * Timeline entries.
     */
    public function timeline(SubjectType $type, string $id, int $limit = 50, ?string $before = null): array
    {
        return $this->support->read(function () use ($type, $id, $limit, $before): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context, $record] = $this->load($type, $id);
            $subjects           = [[$type, $id]];
            $labels             = [];
            if ($type === SubjectType::Company) {
                foreach (array_slice($this->views->affiliations($context->tenant, $type, $id, $this->support->access->visibleOwners($context), $this->viewable($context)), 0, self::TIMELINE_CONTACTS) as $affiliation) {
                    $subjects[]                      = [SubjectType::Contact, $affiliation->contactId];
                    $labels[$affiliation->contactId] = $affiliation->contactName;
                }
            }
            $before  = $before !== null && RecordId::isValid($before) ? $before : null;
            $entries = $this->support->store->activities($context->tenant, $subjects, max(1, min(200, $limit)), $before);
            $names   = $this->views->actorNames($context->tenant, array_map(static fn (Activity $a): ?string => $a->getActorId(), $entries));

            return array_map(static function (Activity $a) use ($record, $labels, $names): ActivityView { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $actor = $a->getActorId();

                return new ActivityView(
                    $a->getId(),
                    $a->getSubjectType(),
                    $a->getSubjectId(),
                    $a->getSubjectId() === $record->getId() ? null : ($labels[$a->getSubjectId()] ?? null),
                    $a->getKind(),
                    $actor === null ? null : ($names[$actor] ?? null),
                    $a->getParams(),
                    $a->getOccurredAt(),
                );
            }, $entries);
        });
    }

    /**
     * Possible duplicates.
     */
    public function duplicates(SubjectType $type, string $id): array
    {
        return $this->support->read(function () use ($type, $id): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context, $record] = $this->load($type, $id);

            return $this->views->many($context->tenant, $type, $this->support->access->viewableRecords($context, $this->support->store->duplicates($record, 10, $this->support->access->visibleOwners($context))));
        });
    }

    /**
     * Notes.
     */
    public function notes(SubjectType $type, string $id): array
    {
        return $this->support->read(function () use ($type, $id): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context] = $this->load($type, $id);

            return $this->noteViews($context, $this->support->store->notes($context->tenant, $type, $id));
        });
    }

    /**
     * Note search (0.5.0): matching notes are read newest first in rounds; each round's records are checked (active,
     * owner scoping, the per-record view decision) before any of its notes is kept, until the limit is filled or
     * {@see self::NOTE_SEARCH_ROUNDS} rounds were read.
     */
    public function searchNotes(string $query, int $limit = 20): array
    {
        $context = $this->support->access->context(CrmPermission::View);
        $text    = mb_substr(trim($query), 0, self::NOTE_QUERY_LENGTH);
        $limit   = max(1, min(self::NOTE_SEARCH_LIMIT, $limit));
        if (mb_strlen($text) < self::NOTE_QUERY_MIN) {
            return [];
        }

        return $this->support->read(function () use ($context, $text, $limit): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $owners = $this->support->access->visibleOwners($context);
            $kept   = [];
            $before = null;
            for ($round = 0; $round < self::NOTE_SEARCH_ROUNDS && count($kept) < $limit; ++$round) {
                $rows    = $this->support->store->searchNotes($context->tenant, $text, self::NOTE_SEARCH_BATCH, $before, $owners);
                $granted = $this->viewableSubjects($context, $rows, [RecordStatus::Active], $owners);
                foreach ($rows as $note) {
                    if (count($kept) < $limit && isset($granted[$note->getSubjectType()->value . ':' . $note->getSubjectId()])) {
                        $kept[] = $note;
                    }
                }
                if (count($rows) < self::NOTE_SEARCH_BATCH) {
                    break;
                }
                $before = $rows[array_key_last($rows)]->getId();
            }
            $views = $this->noteViews($context, $kept);
            $hits  = [];
            foreach ($kept as $i => $note) {
                $hits[] = new NoteHit($note->getSubjectType(), $note->getSubjectId(), $views[$i], $this->excerpt($note->getBody(), $text));
            }

            return $hits;
        });
    }

    /**
     * Notes of several records (0.5.0), in chunks of {@see self::NOTES_OF_CHUNK} records: per chunk the records (one
     * query per type), the view decision for the chunk at once, the note ids (one query) and the notes (one query).
     */
    public function notesOf(array $subjects, int $perSubject = 5): array
    {
        $context    = $this->support->access->context(CrmPermission::View);
        $perSubject = max(1, min(self::NOTES_PER_SUBJECT, $perSubject));
        $wanted     = [];
        foreach ($subjects as $subject) {
            if ($subject instanceof SubjectRef && $subject->type !== SubjectType::Deal) {
                $wanted[$subject->key()] = $subject;
            }
        }

        return $this->support->read(function () use ($context, $wanted, $perSubject): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $owners = $this->support->access->visibleOwners($context);
            $result = [];
            foreach (array_chunk($wanted, self::NOTES_OF_CHUNK, true) as $chunk) {
                $granted   = $this->viewableSubjects($context, array_values($chunk), [RecordStatus::Active, RecordStatus::Trashed], $owners);
                $idsByType = [];
                foreach ($chunk as $key => $subject) {
                    if (isset($granted[$key])) {
                        $result[$key]                       = [];
                        $idsByType[$subject->type->value][] = $subject->id->value;
                    }
                }
                if ($idsByType === []) {
                    continue;
                }
                $rows  = $this->support->store->notesOfMany($context->tenant, $idsByType, $perSubject);
                $views = $this->noteViews($context, $rows);
                foreach ($rows as $i => $note) {
                    $result[$note->getSubjectType()->value . ':' . $note->getSubjectId()][] = $views[$i];
                }
            }

            return $result;
        });
    }

    /**
     * Host links, resolved per call.
     */
    public function links(SubjectType $type, string $id): array
    {
        return $this->support->read(function () use ($type, $id): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context] = $this->load($type, $id);

            return $this->resolveLinks($context, $this->support->store->links($context->tenant, $type, $id));
        });
    }

    /**
     * Host panels.
     */
    public function panels(SubjectType $type, string $id): array
    {
        [$context, $subject, $links] = $this->support->read(function () use ($type, $id): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context, $record] = $this->load($type, $id);
            $links              = array_map(static fn (HostLink $l): HostObjectRef => new HostObjectRef($l->getHostType(), $l->getHostId()), $this->support->store->links($context->tenant, $type, $id));

            return [$context, SubjectRef::of($type, $record->getId(), $record->getOwnerId()), $links];
        });
        $panelContext = new PanelContext($context->tenant, $subject, $context->actor, $links);
        $result       = [];
        foreach ($this->panels as $provider) {
            try {
                if (!$provider->supports($type)) {
                    continue;
                }
                $panel = $provider->panel($panelContext);
            } catch (Throwable $e) {
                if (self::transient($e)) {
                    throw $e;
                }
                $this->logger->warning('A CRM panel provider failed; its panel is skipped.', ['provider' => $provider::class, 'exception' => $e::class]);

                continue;
            }
            $key = $provider->key();
            if ($panel instanceof Panel && preg_match('/\A[a-z0-9_-]{1,40}\z/D', $key) === 1) {
                $result[] = ['key' => $key, 'panel' => $panel];
            }
        }

        return $result;
    }

    /**
     * Consent history.
     */
    public function consents(string $contactId): array
    {
        return $this->support->read(function () use ($contactId): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context] = $this->load(SubjectType::Contact, $contactId);
            $rows      = $this->support->store->consents($context->tenant, $contactId);
            $names     = $this->views->actorNames($context->tenant, array_map(static fn (Consent $c): ?string => $c->getRecordedById(), $rows));
            $views     = [];
            foreach ($rows as $consent) {
                $by      = $consent->getRecordedById();
                $views[] = new ConsentView($consent->getId(), $consent->getPurpose(), $consent->isGranted(), $consent->getSource(), $consent->getEvidence(), $by === null ? null : ($names[$by] ?? null), $consent->getRecordedAt());
            }

            return $views;
        });
    }

    /**
     * Tags.
     */
    public function tags(): array
    {
        $context = $this->support->access->context(CrmPermission::View);

        return $this->support->read(fn (): array => array_map(RecordViews::tag(...), $this->support->store->tags($context->tenant)));
    }

    /**
     * Field definitions.
     */
    public function fields(SubjectType $type, bool $withArchived = false): array
    {
        $context = $this->support->access->context(CrmPermission::View);

        return $this->support->read(fn (): array => array_map(static fn (FieldDefinition $f): FieldView => new FieldView(
            $f->getId(),
            $f->getSubjectType(),
            $f->getFieldKey(),
            $f->getLabel(),
            $f->getType(),
            $f->getChoices(),
            $f->isRequired(),
            $f->getPosition(),
            $f->isArchived(),
            $f->getVersion(),
        ), $this->support->store->fields($context->tenant, $type, $withArchived)));
    }

    /**
     * Owners.
     */
    public function owners(): array
    {
        $context = $this->support->access->context(CrmPermission::View);

        return $this->support->access->owners($context, $this->directory);
    }

    /**
     * Header search (SearchKit bridge, contract section 12): active records of the current tenant whose searchable
     * fields contain the text, at most `$limit`, with the visible total; null when the CRM is not available or the
     * actor may not view (no hint either way). A scope that is a company id of the tenant narrows contacts to it.
     *
     * @return array{list<RecordView>, int}|null
     */
    public function search(SubjectType $type, string $text, int $limit, ?string $scope = null): ?array
    {
        try {
            $context = $this->support->access->context(CrmPermission::View);
        } catch (NotAvailableException|AccessDeniedException) {
            return null;
        }

        return $this->support->read(function () use ($context, $type, $text, $limit, $scope): ?array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $company = null;
            if ($scope !== null) {
                $record = RecordId::isValid($scope) ? $this->support->store->record(SubjectType::Company, $context->tenant, $scope) : null;
                if (!$record instanceof CrmRecord || !$record->isActive() || $type !== SubjectType::Contact) {
                    return null;
                }
                $company = $record->getId();
            }
            [$records, $total] = $this->support->store->search($type, $context->tenant, $text, $limit, $company, $this->support->access->visibleOwners($context));
            $visible           = $this->support->access->viewableRecords($context, $records);

            return [$this->views->many($context->tenant, $type, $visible), max(0, $total - (count($records) - count($visible)))];
        });
    }

    /**
     * The views of notes, in their order, with the authors' names (one lookup); an anonymised author shows as the
     * record's notes always show it.
     *
     * @param list<Note> $rows
     *
     * @return list<NoteView>
     */
    private function noteViews(CrmContext $context, array $rows): array
    {
        $names = $this->views->actorNames($context->tenant, array_map(static fn (Note $n): ?string => $n->getAuthorId(), $rows));
        $notes = [];
        foreach ($rows as $note) {
            $author  = $note->getAuthorId();
            $notes[] = new NoteView($note->getId(), $note->getBody(), $note->isPinned(), $author, $author === null ? null : ($names[$author] ?? null), $note->getVersion(), $note->getCreatedAt(), $note->getUpdatedAt());
        }

        return $notes;
    }

    /**
     * The keys (`<type>:<id>`) of the records of `$items` (notes or subject references) the actor may view: the
     * record exists in the tenant (exact ids), has one of `$statuses`, passes the owner scoping, and the per-record
     * view decision grants it (the same check as a single record, decided for the batch at once).
     *
     * @param list<Note|SubjectRef> $items
     * @param list<RecordStatus> $statuses
     * @param list<string>|null $owners
     *
     * @return array<string, true>
     */
    private function viewableSubjects(CrmContext $context, array $items, array $statuses, ?array $owners): array
    {
        $idsByType = [];
        foreach ($items as $item) {
            [$type, $id]                  = $item instanceof Note ? [$item->getSubjectType(), $item->getSubjectId()] : [$item->type, $item->id->value];
            $idsByType[$type->value][$id] = $id;
        }
        $records = [];
        foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
            $ids = array_values($idsByType[$type->value] ?? []);
            foreach ($ids === [] ? [] : $this->support->store->records($type, $context->tenant, $ids) as $record) {
                if (in_array($record->getStatus(), $statuses, true) && CrmAccess::visible($owners, $record->getOwnerId())) {
                    $records[] = $record;
                }
            }
        }
        $granted = [];
        foreach ($this->support->access->viewableRecords($context, $records) as $record) {
            $granted[$record::type()->value . ':' . $record->getId()] = true;
        }

        return $granted;
    }

    /**
     * Up to {@see NoteHit::EXCERPT_LENGTH} characters of the body around the first match of the text (the start of
     * the body when the database matched in a way PHP does not, e.g. accent-insensitively), with an ellipsis on each
     * cut side.
     */
    private function excerpt(string $body, string $text): string
    {
        $at    = mb_stripos($body, $text, 0, 'UTF-8');
        $start = max(0, ($at === false ? 0 : $at) - (int) ((NoteHit::EXCERPT_LENGTH - mb_strlen($text)) / 2));
        $start = max(0, min($start, mb_strlen($body) - NoteHit::EXCERPT_LENGTH));

        return ($start > 0 ? '…' : '') . mb_substr($body, $start, NoteHit::EXCERPT_LENGTH) . ($start + NoteHit::EXCERPT_LENGTH < mb_strlen($body) ? '…' : '');
    }

    /**
     * The record decision of a page for the call (0.3.0), for related labels.
     *
     * @return callable(list<SubjectRef>): array<string, true>
     */
    private function viewable(CrmContext $context): callable
    {
        /** @param list<SubjectRef> $subjects */
        $viewable = fn (array $subjects): array => $this->support->access->viewable($context, $subjects); // @igor-ignore - a per-call closure; nothing is kept on the service

        return $viewable;
    }

    /**
     * Resolves links through the host's resolvers: a link the resolver does not return is hidden; a type without a
     * resolver shows the stored label (or the type and id) without a URL.
     *
     * @param list<HostLink> $links
     *
     * @return list<LinkView>
     */
    private function resolveLinks(CrmContext $context, array $links): array
    {
        $byType = [];
        foreach ($links as $link) {
            $byType[$link->getHostType()][] = $link;
        }
        $resolvers = [];
        foreach ($this->resolvers as $resolver) {
            $resolvers[$resolver->type()] = $resolver;
        }
        $views = [];
        foreach ($byType as $type => $typeLinks) {
            $resolver = $resolvers[$type] ?? null;
            $resolved = $resolver instanceof CrmHostObjectResolverInterface
                ? $resolver->resolve($context->tenant, array_values(array_map(static fn (HostLink $l): string => $l->getHostId(), $typeLinks)))
                : null;
            foreach ($typeLinks as $link) {
                if ($resolved === null) {
                    $views[] = new LinkView($link->getId(), $type, $link->getHostId(), $link->getLabel() ?? $type . ' ' . $link->getHostId(), null);

                    continue;
                }
                $object = $resolved[$link->getHostId()] ?? null;
                if ($object instanceof HostObjectView && $object->id === $link->getHostId()) {
                    $views[] = new LinkView($link->getId(), $type, $link->getHostId(), $object->label, $object->url);
                }
            }
        }

        return $views;
    }

    /**
     * Whether a failure is a transient storage failure that must reach the write unit (REQ-RUNTIME-003 rule 8).
     */
    public static function transient(Throwable $failure): bool
    {
        for ($e = $failure; $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof TransientStorageFailure || is_a($e, 'Doctrine\\DBAL\\Exception\\RetryableException')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The context and a record the actor may view (any status: a merged or trashed record is shown as such).
     *
     * @return array{CrmContext, CrmRecord}
     */
    private function load(SubjectType $type, string $id): array
    {
        $context = $this->support->access->base();
        if (!RecordId::isValid($id)) {
            throw new NotAvailableException();
        }
        $record = $this->support->store->record($type, $context->tenant, $id);
        if (!$record instanceof CrmRecord) {
            throw new NotAvailableException();
        }
        $this->support->access->require($context, CrmPermission::View, SubjectRef::of($type, $record->getId(), $record->getOwnerId()));

        return [$context, $record];
    }
}
