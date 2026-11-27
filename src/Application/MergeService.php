<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Engagement\EngagementHousekeeping;
use Nowo\CrmKitBundle\Application\Pipeline\PipelineHousekeeping;
use Nowo\CrmKitBundle\Application\Port\ExternalRefHooksInterface;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RecordsMerged;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\MergeRecord;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Read\MergeField;
use Nowo\CrmKitBundle\Domain\Read\MergePreview;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_key_exists;
use function count;
use function in_array;
use function sort;
use function str_starts_with;
use function substr;

use const DATE_ATOM;

/**
 * Merging duplicates (contract section 15.3): both records are locked `FOR UPDATE` in id order and re-checked
 * (active, versions) inside the lock; children move by primary key, unique children are de-duplicated first; the
 * merged record becomes a tombstone pointing at the survivor. Stateless.
 */
final readonly class MergeService implements MergeServiceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private RecordViews $views, private PlanCounter $counter, private ?PipelineHousekeeping $pipeline = null, private ?ExternalRefHooksInterface $refs = null, private ?EngagementHousekeeping $engagements = null)
    {
    }

    /**
     * What the merge would do.
     */
    public function preview(SubjectType $type, string $survivorId, string $otherId): MergePreview
    {
        $context = $this->support->access->base();

        return $this->support->read(function () use ($context, $type, $survivorId, $otherId): MergePreview { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$survivor, $other] = $this->pair($context, $type, $survivorId, $otherId, false);
            $fields             = [];
            foreach (RecordFields::names($type) as $field) {
                $fields[] = new MergeField($field, $survivor->value($field), $other->value($field), $survivor->value($field) === null && $other->value($field) !== null);
            }
            $sCustom = $survivor->getCustom();
            foreach ($other->getCustom() + $sCustom as $key => $unused) {
                $fields[] = new MergeField('custom.' . $key, $sCustom[$key] ?? null, $other->getCustom()[$key] ?? null, !array_key_exists($key, $sCustom));
            }
            $store = $this->support->store;
            $moves = [
                'notes'        => count($store->notes($context->tenant, $type, $otherId)),
                'tags'         => count($store->taggings($context->tenant, $type, [$otherId])),
                'links'        => count($store->links($context->tenant, $type, $otherId)),
                'affiliations' => count($store->affiliations($context->tenant, $type, $otherId)),
                'roles'        => count($store->roleAssignments($context->tenant, $type, [$otherId])),
                'activities'   => count($store->allActivities($context->tenant, $type, $otherId)),
            ];
            if ($type === SubjectType::Contact) {
                $moves['consents'] = count($store->consents($context->tenant, $otherId));
            }

            $owners = $this->support->access->visibleOwners($context);

            /** @param list<SubjectRef> $subjects */
            $viewable = fn (array $subjects): array => $this->support->access->viewable($context, $subjects); // @igor-ignore - a per-call closure; nothing is kept on the service

            return new MergePreview($this->views->one($context->tenant, $survivor, $owners, $viewable), $this->views->one($context->tenant, $other, $owners, $viewable), $fields, $moves);
        });
    }

    /**
     * Merges.
     */
    public function merge(SubjectType $type, string $survivorId, string $otherId, array $takeFromOther, int $survivorVersion, int $otherVersion): void
    {
        $context = $this->support->access->base();
        foreach ($takeFromOther as $field) {
            if (!in_array($field, RecordFields::names($type), true) && !str_starts_with($field, 'custom.')) {
                throw InvalidInputException::field('take', 'error.invalid');
            }
        }
        $this->support->write(function (Effects $effects) use ($context, $type, $survivorId, $otherId, $takeFromOther, $survivorVersion, $otherVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$survivor, $other] = $this->pair($context, $type, $survivorId, $otherId, true);
            if ($survivor->getVersion() !== $survivorVersion || $other->getVersion() !== $otherVersion) {
                throw new ConflictException(ConflictException::MERGE_STALE, $survivorId);
            }
            $store = $this->support->store;
            if ($type === SubjectType::Contact) {
                // The companies of both contacts, after the contacts (the lock order of affiliate()), in id order: their
                // primary-contact flags move with the affiliations.
                $store->lockRecords(SubjectType::Company, $context->tenant, $store->affiliatedCompanyIds($context->tenant, [$survivorId, $otherId]));
            }
            $snapshot = ['values' => $other->values(), 'custom' => $other->getCustom(), 'owner' => $other->getOwnerId(), 'created_at' => $other->getCreatedAt()->format(DATE_ATOM)];
            $values   = [];
            $custom   = $survivor->getCustom();
            $taken    = [];
            foreach ($takeFromOther as $field) {
                if (str_starts_with($field, 'custom.')) {
                    $key = substr($field, 7);
                    if (array_key_exists($key, $other->getCustom())) {
                        $custom[$key] = $other->getCustom()[$key];
                        $taken[]      = $field;
                    }

                    continue;
                }
                $values[$field] = $other->value($field);
                $taken[]        = $field;
            }
            foreach ($other->getCustom() as $key => $value) {
                $custom[$key] ??= $value;
            }
            $otherOwner = $other->getOwnerId();
            // The tombstone releases its unique keys (and every personal value) before the survivor may take them.
            $other->mergeInto($survivorId, $context->now, $context->actor->value);
            $store->flush();
            $survivor->apply($values);
            if ($survivor instanceof Contact) {
                RecordValidator::contactName($survivor->values());
            }
            $survivor->changeCustom($custom);
            if ($survivor->getOwnerId() === null) {
                $survivor->changeOwner($otherOwner);
            }
            $survivor->touch($context->now, $context->actor->value);
            $this->moveChildren($context, $type, $otherId, $survivorId);
            // 0.3.0: deals, stage history, lifecycle stages and next actions follow the survivor.
            $this->pipeline?->moveRecord($context->tenant, $type, $otherId, $survivorId);
            // 0.10.0: the HubSpot references follow the survivor too.
            $this->refs?->move($context->tenant, $type, $otherId, $survivorId);
            // 0.11.0: so do its logged activities, their associations and its meeting attendances.
            $this->engagements?->moveRecord($context->tenant, $type, $otherId, $survivorId);
            $this->counter->release($context->tenant, $type);
            $store->add(new MergeRecord($this->support->id(), $context->tenant->value, $type, $survivorId, $otherId, $snapshot, $taken, $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, $type, $survivorId, ActivityKind::Merged, ['merged' => $otherId, 'fields' => count($taken)]);
            $effects->event(new RecordsMerged($context->tenant, $context->actor, $context->now, $type, $survivorId, $otherId));
            $this->support->audit($effects, $context, 'crm.records.merged', $type, $survivorId, ['merged' => $otherId]);
        }, ConflictException::MERGE_STALE);
    }

    /**
     * Moves every child of the merged record to the survivor; unique children the survivor already has are deleted.
     */
    private function moveChildren(CrmContext $context, SubjectType $type, string $fromId, string $toId): void
    {
        $store  = $this->support->store;
        $tenant = $context->tenant;
        $have   = [];
        foreach ($store->taggings($tenant, $type, [$toId]) as $tagging) {
            $have[$tagging->getTagId()] = true;
        }
        foreach ($store->taggings($tenant, $type, [$fromId]) as $tagging) {
            if (isset($have[$tagging->getTagId()])) {
                $store->remove($tagging); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            } else {
                $tagging->moveTo($toId);
            }
        }
        $have = [];
        foreach ($store->links($tenant, $type, $toId) as $link) {
            $have[$link->getHostType() . "\0" . $link->getHostId()] = true;
        }
        foreach ($store->links($tenant, $type, $fromId) as $link) {
            if (isset($have[$link->getHostType() . "\0" . $link->getHostId()])) {
                $store->remove($link); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            } else {
                $link->moveTo($toId);
            }
        }
        $have = [];
        foreach ($store->roleAssignments($tenant, $type, [$toId]) as $assignment) {
            $have[$assignment->getRoleKey()] = true;
        }
        foreach ($store->roleAssignments($tenant, $type, [$fromId]) as $assignment) {
            if (isset($have[$assignment->getRoleKey()])) {
                $store->remove($assignment); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            } else {
                $assignment->moveTo($toId);
            }
        }
        $this->moveAffiliations($context, $type, $fromId, $toId);
        // Unique children first (deletes before moves), then the rest.
        $store->flush();
        foreach ($store->notes($tenant, $type, $fromId) as $note) {
            $note->moveTo($toId);
        }
        foreach ($store->allActivities($tenant, $type, $fromId) as $activity) {
            $activity->moveTo($toId);
        }
        if ($type === SubjectType::Contact) {
            foreach ($store->consents($tenant, $fromId) as $consent) {
                $consent->moveTo($toId);
            }
        }
    }

    /**
     * Moves the affiliations of the merged record: a pair the survivor already has is deleted after its purposes and
     * its primary flags went to the survivor's row; at most one primary company per contact and one primary
     * contact per company stay (the survivor's win). The companies involved are locked by the caller.
     */
    private function moveAffiliations(CrmContext $context, SubjectType $type, string $fromId, string $toId): void
    {
        $store    = $this->support->store;
        $survivor = [];
        $primary  = false;
        $contact  = false;
        foreach ($store->affiliations($context->tenant, $type, $toId) as $affiliation) {
            $survivor[$type === SubjectType::Contact ? $affiliation->getCompanyId() : $affiliation->getContactId()] = $affiliation;
            $primary                                                                                                = $primary || $affiliation->isPrimary();
            $contact                                                                                                = $contact || $affiliation->isPrimaryContact();
        }
        foreach ($store->affiliations($context->tenant, $type, $fromId) as $affiliation) {
            $counterpart = $type === SubjectType::Contact ? $affiliation->getCompanyId() : $affiliation->getContactId();
            $kept        = $survivor[$counterpart] ?? null;
            if ($kept instanceof Affiliation) {
                $kept->setPurposes([...$kept->getPurposes(), ...$affiliation->getPurposes()]);
                // Contacts: the merged contact's primary company is this shared one and the survivor has none.
                if ($type === SubjectType::Contact && $affiliation->isPrimary() && !$primary) {
                    $kept->setPrimary(true);
                    $primary = true;
                }
                // Contacts: the same company, whose primary contact was the merged one. Companies: the same person,
                // primary contact of the merged company, when the survivor has none.
                if ($affiliation->isPrimaryContact() && ($type === SubjectType::Contact || !$contact)) {
                    $kept->setPrimaryContact(true);
                    $contact = true;
                }
                $store->remove($affiliation); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

                continue;
            }
            if ($type === SubjectType::Contact && $primary) {
                $affiliation->setPrimary(false);
            }
            if ($type === SubjectType::Company && $affiliation->isPrimaryContact()) {
                $affiliation->setPrimaryContact(!$contact);
                $contact = true;
            }
            if ($type === SubjectType::Contact) {
                $affiliation->moveTo($toId, null);
            } else {
                $affiliation->moveTo(null, $toId);
            }
        }
    }

    /**
     * Both records, active, with `edit` on each and `delete` on the other one (locked in id order when `$lock`).
     *
     * @return array{CrmRecord, CrmRecord}
     */
    private function pair(CrmContext $context, SubjectType $type, string $survivorId, string $otherId, bool $lock): array
    {
        if (!RecordId::isValid($survivorId) || !RecordId::isValid($otherId)) {
            throw new NotAvailableException();
        }
        if ($survivorId === $otherId) {
            throw InvalidInputException::field('other', 'error.merge_same');
        }
        $ids = [$survivorId, $otherId];
        sort($ids);
        $records = $lock ? $this->support->store->lockRecords($type, $context->tenant, $ids) : $this->support->store->records($type, $context->tenant, $ids);
        foreach ([$survivorId, $otherId] as $id) {
            $record = $records[$id] ?? null;
            if (!$record instanceof CrmRecord) {
                throw new NotAvailableException();
            }
            $this->support->access->require($context, CrmPermission::Edit, SubjectRef::of($type, $id, $record->getOwnerId()));
            // The merged record ends as a tombstone: removing it needs the delete permission on it too (audit UP-L-1).
            if ($id === $otherId) {
                $this->support->access->require($context, CrmPermission::Delete, SubjectRef::of($type, $id, $record->getOwnerId()));
            }
            if (!$record->isActive()) {
                throw new ConflictException($lock ? ConflictException::MERGE_STALE : ConflictException::TRASHED, $id);
            }
        }

        return [$records[$survivorId], $records[$otherId]];
    }
}
