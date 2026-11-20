<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorProfilesInterface;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_keys;
use function array_map;
use function array_values;

/**
 * Builds the read models of records (contract section 7): owner names from the host directory (checked with ===),
 * tags and affiliations with a fixed number of queries per page. Stateless.
 *
 * @internal
 */
final readonly class RecordViews
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmStoreInterface $store, private CrmActorDirectoryInterface $directory, private PartyCatalogue $catalogue = new PartyCatalogue(), private ?CrmActorProfilesInterface $profiles = null)
    {
    }

    /**
     * Views of records of one type, with tags and configured roles (and affiliations when `$withAffiliations`).
     *
     * @param list<CrmRecord> $records
     * @param list<string>|null $visibleOwners resource scoping of the counterparts ({@see CrmAccess::visibleOwners()})
     * @param (callable(list<SubjectRef>): array<string, true>)|null $viewable the record decision of a page (0.3.0)
     *
     * @return list<RecordView>
     */
    public function many(TenantRef $tenant, SubjectType $type, array $records, bool $withAffiliations = false, ?array $visibleOwners = null, ?callable $viewable = null): array
    {
        if ($records === []) {
            return [];
        }
        $ids  = array_map(static fn (CrmRecord $r): string => $r->getId(), $records);
        $tags = [];
        foreach ($this->store->tags($tenant) as $tag) {
            $tags[$tag->getId()] = $tag;
        }
        $tagged = [];
        foreach ($this->store->taggings($tenant, $type, $ids) as $tagging) {
            $tagged[$tagging->getSubjectId()][$tagging->getTagId()] = true;
        }
        $byRecord = [];
        foreach ($tagged as $recordId => $tagIds) {
            foreach ($tags as $tagId => $tag) {
                if (isset($tagIds[$tagId])) {
                    $byRecord[$recordId][] = self::tag($tag);
                }
            }
        }
        $roles = [];
        foreach ($this->store->roleAssignments($tenant, $type, $ids) as $assignment) {
            $roles[$assignment->getSubjectId()][] = $assignment->getRoleKey();
        }
        // Every owner's name in one resolver call for the page.
        $owners = $this->actorNames($tenant, array_map(static fn (CrmRecord $r): ?string => $r->getOwnerId(), array_values($records)));
        $views  = [];
        foreach ($records as $record) {
            $owner   = $record->getOwnerId();
            $views[] = new RecordView(
                $type,
                $record->getId(),
                $record->label(),
                $record->values(),
                $record->getCustom(),
                $owner,
                $owner === null ? null : ($owners[$owner] ?? null),
                $byRecord[$record->getId()] ?? [],
                $record->getStatus(),
                $record->getMergedIntoId(),
                $record->getVersion(),
                $record->getCreatedAt(),
                $record->getUpdatedAt(),
                $record->getTrashedAt(),
                $withAffiliations ? $this->affiliations($tenant, $type, $record->getId(), $visibleOwners, $viewable) : [],
                $this->catalogue->knownRoles($roles[$record->getId()] ?? []),
            );
        }

        return $views;
    }

    /**
     * One view, with affiliations (counterparts limited to the visible owners).
     *
     * @param list<string>|null $visibleOwners
     * @param (callable(list<SubjectRef>): array<string, true>)|null $viewable the record decision of a page (0.3.0)
     */
    public function one(TenantRef $tenant, CrmRecord $record, ?array $visibleOwners = null, ?callable $viewable = null): RecordView
    {
        return $this->many($tenant, $record::type(), [$record], true, $visibleOwners, $viewable)[0];
    }

    /**
     * The affiliations of a record with both labels (only active counterparts the actor may see).
     *
     * @param list<string>|null $visibleOwners
     * @param (callable(list<SubjectRef>): array<string, true>)|null $viewable the record decision of a page (0.3.0)
     *
     * @return list<AffiliationView>
     */
    public function affiliations(TenantRef $tenant, SubjectType $type, string $id, ?array $visibleOwners = null, ?callable $viewable = null): array
    {
        $links = $this->store->affiliations($tenant, $type, $id);
        if ($links === []) {
            return [];
        }
        $other     = $type === SubjectType::Contact ? SubjectType::Company : SubjectType::Contact;
        $otherIds  = array_map(static fn (Affiliation $a): string => $type === SubjectType::Contact ? $a->getCompanyId() : $a->getContactId(), $links);
        $records   = $this->store->records($other, $tenant, array_values($otherIds));
        $self      = $this->store->record($type, $tenant, $id);
        $selfLabel = $self?->label() ?? '';
        // 0.3.0: the other sides also pass the record decision (RECORD_ACCESS_CHECK), one batch for the record.
        $granted = null;
        if ($viewable !== null) {
            $subjects = [];
            foreach ($records as $record) {
                $subjects[] = SubjectRef::of($other, $record->getId(), $record->getOwnerId());
            }
            $granted = $viewable($subjects);
        }
        $views = [];
        foreach ($links as $link) {
            $counterpart = $records[$type === SubjectType::Contact ? $link->getCompanyId() : $link->getContactId()] ?? null;
            if (!$counterpart instanceof CrmRecord || !$counterpart->isActive() || !CrmAccess::visible($visibleOwners, $counterpart->getOwnerId()) || ($granted !== null && !isset($granted[$other->value . ':' . $counterpart->getId()]))) {
                continue;
            }
            $views[] = new AffiliationView(
                $link->getId(),
                $link->getContactId(),
                $type === SubjectType::Contact ? $selfLabel : $counterpart->label(),
                $link->getCompanyId(),
                $type === SubjectType::Company ? $selfLabel : $counterpart->label(),
                $link->getRole(),
                $link->isPrimary(),
                $link->isPrimaryContact(),
                $this->catalogue->knownPurposes($link->getPurposes()),
            );
        }

        return $views;
    }

    /**
     * The display name of a host actor, or null when the directory does not know it.
     */
    public function actorName(TenantRef $tenant, ?string $actor): ?string
    {
        // The anonymised placeholder is nobody: never sent to the host for a name (audit VD2-7).
        $profile = $actor !== null && ExactId::isValid($actor) && !ActorRef::isReserved($actor) ? $this->directory->actor($tenant, new ActorRef($actor)) : null;

        return $profile instanceof ActorProfile && $profile->ref->value === $actor ? $profile->name : null;
    }

    /**
     * The display names of the actors of one page (0.2.1): one {@see CrmActorProfilesInterface::resolve()} call for all
     * of them, then the directory for the ids it did not name. Keyed by id; null when nobody knows the person.
     *
     * @param list<string|null> $actors ids as stored (nulls and repeats allowed)
     *
     * @return array<string, string|null>
     */
    public function actorNames(TenantRef $tenant, array $actors): array
    {
        $ids = [];
        foreach ($actors as $actor) {
            if ($actor !== null && ExactId::isValid($actor) && !ActorRef::isReserved($actor)) {
                $ids[$actor] = true;
            }
        }
        $ids      = array_map('strval', array_keys($ids));
        $profiles = $ids === [] || !$this->profiles instanceof CrmActorProfilesInterface ? [] : $this->profiles->resolve($ids);
        $names    = [];
        foreach ($ids as $id) {
            $profile    = $profiles[$id] ?? null;
            $names[$id] = $profile instanceof ActorProfile && $profile->ref->value === $id ? $profile->name : $this->actorName($tenant, $id);
        }

        return $names;
    }

    /**
     * A tag's view.
     */
    public static function tag(Tag $tag): TagView
    {
        return new TagView($tag->getId(), $tag->getName(), $tag->getColour(), $tag->getVersion());
    }
}
