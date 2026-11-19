<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\ListResult;
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

use function array_map;
use function array_slice;
use function array_values;
use function is_a;
use function preg_match;

/**
 * The CRM's reads (contract section 7): every call resolves the tenant and checks `view` (on the record when there is
 * one). Panels and host links are asked from the host per call. Stateless.
 */
final readonly class CrmReader implements CrmReaderInterface
{
    /** Contacts of a company whose timeline entries the company page includes. */
    private const TIMELINE_CONTACTS = 200;

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
     * One record.
     */
    public function record(SubjectType $type, string $id): RecordView
    {
        return $this->support->read(function () use ($type, $id): RecordView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context, $record] = $this->load($type, $id);

            return $this->views->one($context->tenant, $record, $this->support->access->visibleOwners($context));
        });
    }

    /**
     * A page of records.
     */
    public function list(ListQuery $query): ListResult
    {
        $context = $this->support->access->context(CrmPermission::View);

        return $this->support->read(function () use ($context, $query): ListResult { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$records, $total] = $this->support->store->list($context->tenant, $query, $this->support->access->visibleOwners($context));

            return new ListResult($this->views->many($context->tenant, $query->type, $records), $total);
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
                foreach (array_slice($this->views->affiliations($context->tenant, $type, $id, $this->support->access->visibleOwners($context)), 0, self::TIMELINE_CONTACTS) as $affiliation) {
                    $subjects[]                      = [SubjectType::Contact, $affiliation->contactId];
                    $labels[$affiliation->contactId] = $affiliation->contactName;
                }
            }
            $before  = $before !== null && RecordId::isValid($before) ? $before : null;
            $entries = $this->support->store->activities($context->tenant, $subjects, max(1, min(200, $limit)), $before);
            $names   = [];

            return array_map(function (Activity $a) use ($context, $record, $labels, &$names): ActivityView { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $actor = $a->getActorId();
                if ($actor !== null && !isset($names[$actor])) {
                    $names[$actor] = $this->views->actorName($context->tenant, $actor);
                }

                return new ActivityView(
                    $a->getId(),
                    $a->getSubjectType(),
                    $a->getSubjectId(),
                    $a->getSubjectId() === $record->getId() ? null : ($labels[$a->getSubjectId()] ?? null),
                    $a->getKind(),
                    $actor === null ? null : $names[$actor],
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

            return $this->views->many($context->tenant, $type, $this->support->store->duplicates($record, 10, $this->support->access->visibleOwners($context)));
        });
    }

    /**
     * Notes.
     */
    public function notes(SubjectType $type, string $id): array
    {
        return $this->support->read(function () use ($type, $id): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$context] = $this->load($type, $id);
            $names     = [];
            $notes     = [];
            foreach ($this->support->store->notes($context->tenant, $type, $id) as $note) {
                $author = $note->getAuthorId();
                if ($author !== null && !isset($names[$author])) {
                    $names[$author] = $this->views->actorName($context->tenant, $author);
                }
                $notes[] = new NoteView($note->getId(), $note->getBody(), $note->isPinned(), $author, $author === null ? null : $names[$author], $note->getVersion(), $note->getCreatedAt(), $note->getUpdatedAt());
            }

            return $notes;
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
            $names     = [];
            $views     = [];
            foreach ($this->support->store->consents($context->tenant, $contactId) as $consent) {
                $by = $consent->getRecordedById();
                if ($by !== null && !isset($names[$by])) {
                    $names[$by] = $this->views->actorName($context->tenant, $by);
                }
                $views[] = new ConsentView($consent->getId(), $consent->getPurpose(), $consent->isGranted(), $consent->getSource(), $consent->getEvidence(), $by === null ? null : $names[$by], $consent->getRecordedAt());
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

        return $this->directory->owners($context->tenant);
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

            return [$this->views->many($context->tenant, $type, $records), $total];
        });
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
