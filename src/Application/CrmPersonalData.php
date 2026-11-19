<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\ContactErased;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\MergeRecord;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;

use function array_filter;
use function array_map;
use function array_values;

use const DATE_ATOM;

/**
 * GDPR export and erasure of a contact (contract section 16). Erasure blanks the personal columns, deletes notes,
 * taggings, links, affiliations, timeline entries and merge traces of the contact, keeps consent rows without their
 * evidence, blanks the merge tombstones that point at it (recursively, under lock) with their merge traces, and is
 * idempotent. Stateless.
 */
final readonly class CrmPersonalData implements CrmPersonalDataInterface
{
    /** Name given to an erased contact. */
    public const PLACEHOLDER = 'Erased contact';

    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private CrmReader $reader, private PlanCounter $counter)
    {
    }

    /**
     * The contact's data.
     */
    public function export(string $contactId): array
    {
        $context = $this->support->access->base();
        $data    = $this->support->write(function (Effects $effects) use ($context, $contactId): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record = $this->support->load($context, SubjectType::Contact, $contactId, CrmPermission::Manage, anyStatus: true);
            $store  = $this->support->store;
            $tags   = [];
            foreach ($store->tags($context->tenant) as $tag) {
                $tags[$tag->getId()] = $tag->getName();
            }
            $data = [
                'contact'  => ['id' => $record->getId(), 'status' => $record->getStatus()->value, 'values' => $record->values(), 'custom' => $record->getCustom(), 'owner' => $record->getOwnerId(), 'created_at' => $record->getCreatedAt()->format(DATE_ATOM), 'updated_at' => $record->getUpdatedAt()->format(DATE_ATOM)],
                'tags'     => array_values(array_map(static fn (Tagging $t): string => $tags[$t->getTagId()] ?? '', $store->taggings($context->tenant, SubjectType::Contact, [$contactId]))),
                'notes'    => array_map(static fn (Note $n): array => ['body' => $n->getBody(), 'created_at' => $n->getCreatedAt()->format(DATE_ATOM)], $store->notes($context->tenant, SubjectType::Contact, $contactId)),
                'consents' => array_map(static fn (Consent $c): array => ['purpose' => $c->getPurpose(), 'granted' => $c->isGranted(), 'source' => $c->getSource()->value, 'evidence' => $c->getEvidence(), 'recorded_at' => $c->getRecordedAt()->format(DATE_ATOM)], $store->consents($context->tenant, $contactId)),
                'timeline' => array_map(static fn (Activity $a): array => ['kind' => $a->getKind()->value, 'params' => $a->getParams(), 'occurred_at' => $a->getOccurredAt()->format(DATE_ATOM)], $store->allActivities($context->tenant, SubjectType::Contact, $contactId)),
                'links'    => array_map(static fn (HostLink $l): array => ['type' => $l->getHostType(), 'id' => $l->getHostId()], $store->links($context->tenant, SubjectType::Contact, $contactId)),
                'merges'   => array_map(static fn (MergeRecord $m): array => ['into' => $m->getSurvivorId(), 'snapshot' => $m->getSnapshot(), 'merged_at' => $m->getMergedAt()->format(DATE_ATOM)], $store->mergesOf($context->tenant, $contactId)),
            ];
            $this->support->activity($context, SubjectType::Contact, $contactId, ActivityKind::Exported);
            $this->support->audit($effects, $context, 'crm.contact.exported', SubjectType::Contact, $contactId);

            return $data;
        });
        $data['companies'] = array_map(static fn (AffiliationView $a): array => ['company' => $a->companyName, 'role' => $a->role, 'primary' => $a->primary], $this->reader->record(SubjectType::Contact, $contactId)->affiliations);

        return $data;
    }

    /**
     * Erases the contact.
     */
    public function erase(string $contactId): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $contactId): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record = $this->support->load($context, SubjectType::Contact, $contactId, CrmPermission::Manage, anyStatus: true, lock: true);
            if (!$record instanceof Contact || $record->getStatus() === RecordStatus::Erased) {
                return;
            }
            $store = $this->support->store;
            foreach ($store->taggings($context->tenant, SubjectType::Contact, [$contactId]) as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($store->notes($context->tenant, SubjectType::Contact, $contactId) as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($store->links($context->tenant, SubjectType::Contact, $contactId) as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($store->affiliations($context->tenant, SubjectType::Contact, $contactId) as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($store->allActivities($context->tenant, SubjectType::Contact, $contactId) as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($store->mergesOf($context->tenant, $contactId) as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($store->consents($context->tenant, $contactId) as $row) {
                $row->anonymise();
            }
            // The merge tombstones of this contact, and theirs in turn: locked, blanked, their merge traces deleted.
            $seen  = [$contactId => true];
            $level = [$contactId];
            while ($level !== []) {
                $found = array_values(array_filter($store->lockTombstones(SubjectType::Contact, $context->tenant, $level), static fn (CrmRecord $t): bool => !isset($seen[$t->getId()])));
                $level = [];
                foreach ($found as $tombstone) {
                    $seen[$tombstone->getId()] = true;
                    $level[]                   = $tombstone->getId();
                    $tombstone->anonymise($context->now, $context->actor->value);
                    foreach ($store->mergesOf($context->tenant, $tombstone->getId()) as $row) {
                        $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                    }
                }
            }
            if ($record->isActive()) {
                $this->counter->release($context->tenant, SubjectType::Contact);
            }
            $record->erase(self::PLACEHOLDER, $context->now, $context->actor->value);
            $effects->event(new ContactErased($context->tenant, $context->actor, $context->now, $contactId));
            $this->support->audit($effects, $context, 'crm.contact.erased', SubjectType::Contact, $contactId);
        });
    }
}
