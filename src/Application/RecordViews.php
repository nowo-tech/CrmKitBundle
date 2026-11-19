<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

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
    public function __construct(private CrmStoreInterface $store, private CrmActorDirectoryInterface $directory, private PartyCatalogue $catalogue = new PartyCatalogue())
    {
    }

    /**
     * Views of records of one type, with tags and configured roles (and affiliations when `$withAffiliations`).
     *
     * @param list<CrmRecord> $records
     * @param list<string>|null $visibleOwners resource scoping of the counterparts ({@see CrmAccess::visibleOwners()})
     *
     * @return list<RecordView>
     */
    public function many(TenantRef $tenant, SubjectType $type, array $records, bool $withAffiliations = false, ?array $visibleOwners = null): array
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
        $owners = [];
        $views  = [];
        foreach ($records as $record) {
            $owner = $record->getOwnerId();
            if ($owner !== null && !isset($owners[$owner])) {
                $owners[$owner] = $this->actorName($tenant, $owner);
            }
            $views[] = new RecordView(
                $type,
                $record->getId(),
                $record->label(),
                $record->values(),
                $record->getCustom(),
                $owner,
                $owner === null ? null : $owners[$owner],
                $byRecord[$record->getId()] ?? [],
                $record->getStatus(),
                $record->getMergedIntoId(),
                $record->getVersion(),
                $record->getCreatedAt(),
                $record->getUpdatedAt(),
                $record->getTrashedAt(),
                $withAffiliations ? $this->affiliations($tenant, $type, $record->getId(), $visibleOwners) : [],
                $this->catalogue->knownRoles($roles[$record->getId()] ?? []),
            );
        }

        return $views;
    }

    /**
     * One view, with affiliations (counterparts limited to the visible owners).
     *
     * @param list<string>|null $visibleOwners
     */
    public function one(TenantRef $tenant, CrmRecord $record, ?array $visibleOwners = null): RecordView
    {
        return $this->many($tenant, $record::type(), [$record], true, $visibleOwners)[0];
    }

    /**
     * The affiliations of a record with both labels (only active counterparts the actor may see).
     *
     * @param list<string>|null $visibleOwners
     *
     * @return list<AffiliationView>
     */
    public function affiliations(TenantRef $tenant, SubjectType $type, string $id, ?array $visibleOwners = null): array
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
        $views     = [];
        foreach ($links as $link) {
            $counterpart = $records[$type === SubjectType::Contact ? $link->getCompanyId() : $link->getContactId()] ?? null;
            if (!$counterpart instanceof CrmRecord || !$counterpart->isActive() || !CrmAccess::visible($visibleOwners, $counterpart->getOwnerId())) {
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
        $profile = $actor !== null && ExactId::isValid($actor) ? $this->directory->actor($tenant, new ActorRef($actor)) : null;

        return $profile instanceof ActorProfile && $profile->ref->value === $actor ? $profile->name : null;
    }

    /**
     * A tag's view.
     */
    public static function tag(Tag $tag): TagView
    {
        return new TagView($tag->getId(), $tag->getName(), $tag->getColour(), $tag->getVersion());
    }
}
