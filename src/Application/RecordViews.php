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
    public function __construct(private CrmStoreInterface $store, private CrmActorDirectoryInterface $directory)
    {
    }

    /**
     * Views of records of one type, with tags (and affiliations when `$withAffiliations`).
     *
     * @param list<CrmRecord> $records
     *
     * @return list<RecordView>
     */
    public function many(TenantRef $tenant, SubjectType $type, array $records, bool $withAffiliations = false): array
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
                $withAffiliations ? $this->affiliations($tenant, $type, $record->getId()) : [],
            );
        }

        return $views;
    }

    /**
     * One view, with affiliations.
     */
    public function one(TenantRef $tenant, CrmRecord $record): RecordView
    {
        return $this->many($tenant, $record::type(), [$record], true)[0];
    }

    /**
     * The affiliations of a record with both labels (only active counterparts).
     *
     * @return list<AffiliationView>
     */
    public function affiliations(TenantRef $tenant, SubjectType $type, string $id): array
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
            if (!$counterpart instanceof CrmRecord || !$counterpart->isActive()) {
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
