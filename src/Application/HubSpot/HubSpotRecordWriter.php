<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\PlanCounter;
use Nowo\CrmKitBundle\Application\RecordValidator;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotAssociation;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotRecord;
use Nowo\CrmKitBundle\Domain\Value\CompanyDomain;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function mb_strtolower;
use function sort;

/**
 * Settles one page of HubSpot companies or contacts inside the caller's unit (0.10.0, design section 12): per record
 * the external reference first (always an update; erased ⇒ skipped, trashed ⇒ left alone, merged ⇒ its survivor),
 * then the duplicate key (contacts by e-mail, companies by a single active domain) under the duplicate policy, then a
 * create under the plan limit; the record, its reference and its affiliations are written in the same unit, so a
 * failure leaves none of them. Every record the page may change is locked first, in id order, contacts before
 * companies. An empty HubSpot value never clears a CRM value. Stateless.
 *
 * @internal
 */
final readonly class HubSpotRecordWriter
{
    /** Most merge hops followed from a reference. */
    private const MAX_HOPS = 10;

    /**
     * Wires the collaborators.
     */
    /**
     * @param bool $ownerRule `security.resource_scoping`: an unattended run writes only records without an owner or
     *                        owned by the run's actor (the person who confirmed); any other needs a person
     */
    public function __construct(private WriteSupport $support, private HubSpotStoreInterface $store, private PlanCounter $counter, private bool $ownerRule = false)
    {
    }

    /**
     * Whether an unattended run may write the record on its own: always without the owner rule; with it, only a record
     * without an owner or owned by the run's actor.
     *
     * @throws NeedsPersonException
     */
    public function mayWriteUnattended(CrmContext $context, CrmRecord $record): void
    {
        $owner = $record->getOwnerId();
        if ($this->ownerRule && $owner !== null && $owner !== $context->actor->value) {
            throw new NeedsPersonException();
        }
    }

    /**
     * Settles the records: one row per record (outcome, record id, message key, HubSpot id) and the associations it
     * could not write (the other side has no active record of the same account).
     *
     * @param list<HubSpotRecord> $records
     * @param list<HubSpotAssociation> $associations contact ↔ company (contacts only)
     * @param array<array-key, string> $mapping property => target
     * @param array<string, string> $owners `o<HubSpot owner id>` => actor id
     *
     * @return array{rows: list<array{RowOutcome, string|null, string|null, string}>, skipped: int}
     */
    public function settle(CrmContext $context, string $account, HubSpotObject $object, array $records, array $associations, array $mapping, array $owners, DuplicatePolicy $policy, string $importId, bool $attended): array
    {
        $type    = $object->subjectType();
        $refs    = $this->store->refs($context->tenant, $account, $object->refType(), array_map(static fn (HubSpotRecord $r): string => $r->id->value, $records));
        $targets = $this->targets($context, $type, $refs);
        $mapped  = [];
        $keys    = [];
        foreach ($records as $record) {
            $mapped[$record->id->value] = HubSpotMapper::map($object, $record, $mapping);
            if (!isset($targets[$record->id->value])) {
                $keys[$record->id->value] = $this->key($type, $mapped[$record->id->value]['values']);
            }
        }
        // Locks: contacts first, then companies, each kind in id order (the order of every other path).
        $byKey     = $type === SubjectType::Contact ? $this->support->store->contactIdsByEmailKeys($context->tenant, $this->strings($keys)) : $this->domainMatches($context, $this->strings($keys));
        $locked    = $this->lock($context, $type, [...array_values($targets), ...$byKey]);
        $companies = [];
        $companyOf = [];
        if ($type === SubjectType::Contact && $associations !== []) {
            $companyRefs = $this->store->refs($context->tenant, $account, 'company', array_values(array_unique(array_map(static fn (HubSpotAssociation $a): string => $a->company->value, $associations))));
            $companyOf   = $this->targets($context, SubjectType::Company, $companyRefs);
            $companies   = $this->lock($context, SubjectType::Company, array_values($companyOf));
        }
        $fields  = $this->support->store->fields($context->tenant, $type, true);
        $rows    = [];
        $skipped = 0;
        foreach ($records as $record) {
            $hid                      = $record->id->value;
            [$outcome, $id, $message] = $this->one($context, $account, $object, $hid, $refs[$hid] ?? null, $targets[$hid] ?? null, $keys[$hid] ?? null, $locked, $mapped[$hid], $owners, $fields, $policy, $importId, $attended);
            $rows[]                   = [$outcome, $id, $message, $hid];
            if ($id !== null && $type === SubjectType::Contact && ($outcome === RowOutcome::Created || $outcome === RowOutcome::Updated)) {
                $skipped += $this->affiliate($context, $id, array_values(array_filter($associations, static fn (HubSpotAssociation $a): bool => $a->contact->value === $hid)), $companyOf, $companies, $attended);
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * One record: [outcome, record id, message key].
     *
     * @param array<string, CrmRecord> $locked
     * @param array{values: array<string, string>, custom: array<string, string>, owner: string|null, note: string|null} $mapped
     * @param array<string, string> $owners
     * @param list<FieldDefinition> $fields
     *
     * @return array{RowOutcome, string|null, string|null}
     */
    private function one(CrmContext $context, string $account, HubSpotObject $object, string $hid, ?ExternalRef $ref, ?string $target, ?string $key, array $locked, array $mapped, array $owners, array $fields, DuplicatePolicy $policy, string $importId, bool $attended): array
    {
        $type = $object->subjectType();
        if ($ref instanceof ExternalRef && $ref->isErased()) {
            // Q-H6: an erased person never comes back.
            return [RowOutcome::Duplicate, null, 'error.record_erased'];
        }
        $record = $target === null ? null : ($locked[$target] ?? null);
        if ($record instanceof CrmRecord) {
            $refused = $this->refused($context, $record, $attended);

            return $refused ?? $this->update($context, $record, $mapped, $owners, $fields, $importId, $attended);
        }
        $match = null;
        if ($key !== null) {
            $matches = array_values(array_filter($locked, static fn (CrmRecord $r): bool => $type === SubjectType::Contact ? ($r instanceof Contact && $r->getEmailKey() === $key) : ($r instanceof Company && $r->getDomainKey() === $key && $r->isActive())));
            if (count($matches) > 1) {
                return [RowOutcome::Duplicate, null, 'error.ambiguous_domain'];
            }
            $match = $matches[0] ?? null;
        }
        if ($match instanceof CrmRecord) {
            if (!$match->isActive() || $policy === DuplicatePolicy::Skip) {
                return [RowOutcome::Duplicate, $this->visible($context, $match, $attended), $match->isActive() ? null : 'error.record_trashed'];
            }
            $result = $this->update($context, $match, $mapped, $owners, $fields, $importId, $attended);
        } else {
            $result = $this->create($context, $type, $mapped, $owners, $fields, $importId);
        }
        if ($result[1] !== null) {
            if ($ref instanceof ExternalRef) {
                // The reference outlived its record (deleted by a host): it now names the new one.
                $this->store->retarget($ref, $result[1], $context->now);
            } else {
                $this->support->store->add(new ExternalRef($this->support->id(), $context->tenant->value, $account, $object->refType(), $hid, $object->refType(), $result[1], $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }

        return $result;
    }

    /**
     * The outcome of a referenced record that is not updated (erased, trashed or merged meanwhile), or null.
     *
     * @return array{RowOutcome, string|null, string|null}|null
     */
    private function refused(CrmContext $context, CrmRecord $record, bool $attended): ?array
    {
        return match ($record->getStatus()) {
            RecordStatus::Erased  => [RowOutcome::Duplicate, null, 'error.record_erased'],
            RecordStatus::Trashed => [RowOutcome::Duplicate, $this->visible($context, $record, $attended), 'error.record_trashed'],
            RecordStatus::Merged  => [RowOutcome::Duplicate, null, 'error.record_merged'],
            RecordStatus::Active  => null,
        };
    }

    /**
     * Updates a record with the non-empty values; an attended run needs `edit` on it.
     *
     * @param array{values: array<string, string>, custom: array<string, string>, owner: string|null, note: string|null} $mapped
     * @param array<string, string> $owners
     * @param list<FieldDefinition> $fields
     *
     * @return array{RowOutcome, string|null, string|null}
     */
    private function update(CrmContext $context, CrmRecord $record, array $mapped, array $owners, array $fields, string $importId, bool $attended): array
    {
        $type = $record::type();
        if ($attended && !$this->may($context, CrmPermission::Edit, $record)) {
            return [RowOutcome::Invalid, null, 'error.forbidden'];
        }
        if (!$attended) {
            // Audit ZAM-1: nobody signed in decides on this record; under resource scoping only the confirming person's
            // own (or ownerless) records are written, any other waits for "Continue".
            $this->mayWriteUnattended($context, $record);
        }

        try {
            $values = RecordValidator::values($type, $mapped['values'], false);
            if ($record instanceof Contact && ($values['email'] ?? null) !== null) {
                $other = $this->support->store->contactByEmailKey($context->tenant, mb_strtolower((string) $values['email'], 'UTF-8'));
                if ($other instanceof Contact && $other->getId() !== $record->getId()) {
                    return [RowOutcome::Invalid, null, 'error.email_taken'];
                }
            }
            if ($record instanceof Contact) {
                RecordValidator::contactName($values + $record->values());
            }
            $custom = RecordValidator::custom($mapped['custom'] + $record->getCustom(), $fields, $record->getCustom());
        } catch (InvalidInputException $e) {
            return [RowOutcome::Invalid, null, array_values($e->errors)[0] ?? 'error.invalid'];
        }
        $record->apply($values);
        $record->changeCustom($custom);
        $owner = $mapped['owner'] === null ? null : ($owners['o' . $mapped['owner']] ?? null);
        if ($owner !== null && $attended) {
            // An existing record changes owner only when a person runs the page (never unattended, audit ZAM-1).
            $record->changeOwner($owner);
        }
        $record->touch($context->now, $context->actor->value);
        $this->support->activity($context, $type, $record->getId(), ActivityKind::Imported, ['import' => $importId]);

        return [RowOutcome::Updated, $record->getId(), $mapped['note']];
    }

    /**
     * Creates a record under the plan limit.
     *
     * @param array{values: array<string, string>, custom: array<string, string>, owner: string|null, note: string|null} $mapped
     * @param array<string, string> $owners
     * @param list<FieldDefinition> $fields
     *
     * @return array{RowOutcome, string|null, string|null}
     */
    private function create(CrmContext $context, SubjectType $type, array $mapped, array $owners, array $fields, string $importId): array
    {
        try {
            $values = RecordValidator::values($type, $mapped['values'], true);
            if ($type === SubjectType::Contact) {
                // (An e-mail stored meanwhile by another writer is refused by its unique key: the page settles again.)
                RecordValidator::contactName($values);
            }
            $custom = RecordValidator::custom($mapped['custom'], $fields, []);
        } catch (InvalidInputException $e) {
            return [RowOutcome::Invalid, null, array_values($e->errors)[0] ?? 'error.invalid'];
        }
        if (!$this->counter->tryTake($context->tenant, $type)) {
            return [RowOutcome::Limit, null, null];
        }
        $record = $type === SubjectType::Company
            ? new Company($this->support->id(), $context->tenant->value, $context->now, $context->actor->value)
            : new Contact($this->support->id(), $context->tenant->value, $context->now, $context->actor->value);
        $record->apply($values);
        $record->changeCustom($custom);
        $record->changeOwner($mapped['owner'] === null ? null : ($owners['o' . $mapped['owner']] ?? null));
        $this->support->store->add($record); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->support->store->flush();
        $this->support->activity($context, $type, $record->getId(), ActivityKind::Imported, ['import' => $importId]);

        return [RowOutcome::Created, $record->getId(), $mapped['note']];
    }

    /**
     * Adds the contact's affiliations to the companies of the same account that exist (add only; HubSpot's primary
     * company becomes the contact's primary one when it has none). How many associations could not be written.
     *
     * @param list<HubSpotAssociation> $associations
     * @param array<string, string> $companyOf HubSpot company id => company id
     * @param array<string, CrmRecord> $companies locked companies by id
     */
    private function affiliate(CrmContext $context, string $contactId, array $associations, array $companyOf, array $companies, bool $attended): int
    {
        $store   = $this->support->store;
        $have    = [];
        $primary = false;
        foreach ($store->affiliations($context->tenant, SubjectType::Contact, $contactId) as $affiliation) {
            $have[$affiliation->getCompanyId()] = true;
            $primary                            = $primary || $affiliation->isPrimary();
        }
        $skipped = 0;
        foreach ($associations as $association) {
            $company = $companies[$companyOf[$association->company->value] ?? ''] ?? null;
            if (!$company instanceof Company || !$company->isActive() || ($attended && !$this->may($context, CrmPermission::View, $company))) {
                ++$skipped;

                continue;
            }
            if (!$attended) {
                $this->mayWriteUnattended($context, $company);
            }
            if (isset($have[$company->getId()])) {
                continue;
            }
            $first = $association->primary && !$primary;
            $store->add(new Affiliation($this->support->id(), $context->tenant->value, $contactId, $company->getId(), null, $first, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $have[$company->getId()] = true;
            $primary                 = $primary || $first;
        }

        return $skipped;
    }

    /**
     * The final record id behind each live reference (merges followed; the reference moves to the survivor). 0.12.0:
     * also how the engagements import finds the records of their associations.
     *
     * @param array<string, ExternalRef> $refs
     *
     * @return array<string, string> HubSpot id => record id
     */
    public function targets(CrmContext $context, SubjectType $type, array $refs): array
    {
        // Audit ZDE-L1: one query loads the first hop of every reference; record() then reads the identity map.
        $this->support->store->records($type, $context->tenant, array_values(array_unique(array_map(static fn (ExternalRef $r): string => $r->getTargetId(), $refs))));
        $targets = [];
        foreach ($refs as $hid => $ref) {
            if ($ref->isErased()) {
                continue;
            }
            $id = $ref->getTargetId();
            for ($hop = 0; $hop < self::MAX_HOPS; ++$hop) {
                $record = $this->support->store->record($type, $context->tenant, $id);
                if (!$record instanceof CrmRecord) {
                    continue 2;
                }
                if ($record->getStatus() !== RecordStatus::Merged || $record->getMergedIntoId() === null) {
                    break;
                }
                $id = $record->getMergedIntoId();
            }
            if ($id !== $ref->getTargetId()) {
                $this->store->retarget($ref, $id, $context->now);
            }
            $targets[(string) $hid] = $id;
        }

        return $targets;
    }

    /**
     * Locks records of a type in id order.
     *
     * @param list<string> $ids
     *
     * @return array<string, CrmRecord>
     */
    private function lock(CrmContext $context, SubjectType $type, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $this->support->store->lockRecords($type, $context->tenant, $ids);
    }

    /**
     * The duplicate key of a record without a reference: the contact's e-mail key, the company's domain key.
     *
     * @param array<string, string> $values
     */
    private function key(SubjectType $type, array $values): ?string
    {
        if ($type === SubjectType::Contact) {
            return isset($values['email']) ? mb_strtolower($values['email'], 'UTF-8') : null;
        }

        return CompanyDomain::fromWebsite($values['website'] ?? null);
    }

    /**
     * The companies whose domain key is one of the keys (any status; the active ones decide).
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function domainMatches(CrmContext $context, array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            foreach ($this->support->store->matching(SubjectType::Company, $context->tenant, 'domainKey', $key, false) as $company) {
                $ids[] = $company->getId();
            }
        }

        return $ids;
    }

    /**
     * The non-null keys.
     *
     * @param array<string, string|null> $keys
     *
     * @return list<string>
     */
    private function strings(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, static fn (?string $k): bool => $k !== null)));
    }

    /**
     * The record id, when the person running the import may see it (an unattended run names none).
     */
    private function visible(CrmContext $context, CrmRecord $record, bool $attended): ?string
    {
        return $attended && $this->may($context, CrmPermission::View, $record) ? $record->getId() : null;
    }

    /**
     * Whether the acting person holds the permission on the record.
     */
    private function may(CrmContext $context, CrmPermission $permission, CrmRecord $record): bool
    {
        try {
            $this->support->access->require($context, $permission, SubjectRef::of($record::type(), $record->getId(), $record->getOwnerId()));
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }
}
