<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RecordCreated;
use Nowo\CrmKitBundle\Domain\Event\RecordPurged;
use Nowo\CrmKitBundle\Domain\Event\RecordRestored;
use Nowo\CrmKitBundle\Domain\Event\RecordTrashed;
use Nowo\CrmKitBundle\Domain\Event\RecordUpdated;
use Nowo\CrmKitBundle\Domain\Event\TagsChanged;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_filter;
use function array_map;
use function array_merge;
use function count;
use function implode;
use function in_array;
use function mb_strlen;
use function mb_strtolower;
use function trim;

/**
 * Companies and contacts (contract sections 7, 9 and 15): every write is one write unit with its version check, the
 * plan counter and the e-mail unique key; events and audit after the commit. Stateless.
 */
final readonly class RecordService implements RecordServiceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WriteSupport $support,
        private PlanCounter $counter,
        private Taggings $taggings,
        private CrmActorDirectoryInterface $directory,
        private RecordPurger $purger,
        private Roles $roles,
        private PartyCatalogue $catalogue,
    ) {
    }

    /**
     * Creates a company or contact.
     */
    public function create(RecordInput $input): string
    {
        $context = $this->support->access->context(CrmPermission::Edit);
        $values  = RecordValidator::values($input->type, $input->values, true);
        $owner   = $this->owner($context, $input);
        $roles   = $input->roles === null ? [] : $this->catalogue->checkRoles($input->roles);

        return $this->guardEmail($context, $input, null, function () use ($context, $input, $values, $owner, $roles): string {
            return $this->support->write(function (Effects $effects) use ($context, $input, $values, $owner, $roles): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $store  = $this->support->store;
                $id     = $this->support->id();
                $record = $input->type === SubjectType::Company
                    ? new Company($id, $context->tenant->value, $context->now, $context->actor->value)
                    : new Contact($id, $context->tenant->value, $context->now, $context->actor->value);
                $record->apply($values);
                if ($record instanceof Contact) {
                    RecordValidator::contactName($record->values());
                }
                $record->changeOwner($owner);
                $record->changeCustom(RecordValidator::custom($input->custom ?? [], $store->fields($context->tenant, $input->type, true), []));
                $this->counter->take($context->tenant, $input->type);
                $store->add($record); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $store->flush();
                $this->support->activity($context, $input->type, $id, ActivityKind::Created);
                if ($input->tagIds !== null && $input->tagIds !== []) {
                    $this->taggings->set($context, $input->type, $id, $input->tagIds, $effects); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
                if ($roles !== []) {
                    $this->roles->set($context, $input->type, $id, $roles, $effects); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
                $effects->event(new RecordCreated($context->tenant, $context->actor, $context->now, $input->type, $id));
                $this->support->audit($effects, $context, 'crm.' . $input->type->value . '.created', $input->type, $id);

                return $id;
            });
        });
    }

    /**
     * Edits a company or contact.
     */
    public function update(string $id, RecordInput $input, int $expectedVersion): int
    {
        $context = $this->support->access->base();
        $values  = RecordValidator::values($input->type, $input->values, false);
        $roles   = $input->roles === null ? null : $this->catalogue->checkRoles($input->roles);

        return $this->guardEmail($context, $input, $id, function () use ($context, $id, $input, $values, $expectedVersion, $roles): int {
            return $this->support->write(function (Effects $effects) use ($context, $id, $input, $values, $expectedVersion, $roles): int { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $store  = $this->support->store;
                $record = $this->support->load($context, $input->type, $id, CrmPermission::Edit);
                if ($record->getVersion() !== $expectedVersion) {
                    throw new ConflictException(ConflictException::STALE, $id);
                }
                $wanted  = $input->owner === '' ? null : $input->owner;
                $owner   = $input->setOwner && $wanted !== $record->getOwnerId() ? $this->owner($context, $input) : $record->getOwnerId();
                $changed = $record->apply($values);
                if ($record instanceof Contact) {
                    RecordValidator::contactName($record->values());
                }
                if ($input->custom !== null) {
                    $changed = array_merge($changed, $record->changeCustom(RecordValidator::custom($input->custom, $store->fields($context->tenant, $input->type, true), $record->getCustom())));
                }
                $ownerChanged                = $record->changeOwner($owner);
                [$added, $removed]           = $input->tagIds === null ? [[], []] : $this->taggings->set($context, $input->type, $id, $input->tagIds, $effects); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                [$rolesAdded, $rolesRemoved] = $roles === null ? [[], []] : $this->roles->set($context, $input->type, $id, $roles, $effects); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                if ($changed === [] && !$ownerChanged && $added === [] && $removed === [] && $rolesAdded === [] && $rolesRemoved === []) {
                    return $record->getVersion();
                }
                $record->touch($context->now, $context->actor->value);
                if ($changed !== []) {
                    $this->support->activity($context, $input->type, $id, ActivityKind::Updated, ['fields' => implode(',', $changed)]);
                    $effects->event(new RecordUpdated($context->tenant, $context->actor, $context->now, $input->type, $id, $changed));
                }
                if ($ownerChanged) {
                    $this->support->activity($context, $input->type, $id, ActivityKind::OwnerChanged, ['owner' => $owner]);
                }
                if ($added !== [] || $removed !== []) {
                    $effects->event(new TagsChanged($context->tenant, $context->actor, $context->now, $input->type, $id, $added, $removed));
                }
                $store->flush();
                $this->support->audit($effects, $context, 'crm.' . $input->type->value . '.updated', $input->type, $id, ['fields' => count($changed), 'owner_changed' => $ownerChanged]);

                return $record->getVersion();
            });
        });
    }

    /**
     * Trashes a record.
     */
    public function trash(SubjectType $type, string $id, int $expectedVersion): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $type, $id, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record = $this->support->load($context, $type, $id, CrmPermission::Edit, lock: true);
            if ($record->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $record->trash($context->now, $context->actor->value);
            $this->counter->release($context->tenant, $type);
            $this->support->activity($context, $type, $id, ActivityKind::Trashed);
            $effects->event(new RecordTrashed($context->tenant, $context->actor, $context->now, $type, $id));
            $this->support->audit($effects, $context, 'crm.' . $type->value . '.trashed', $type, $id);
        });
    }

    /**
     * Restores a trashed record.
     */
    public function restore(SubjectType $type, string $id): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $type, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record = $this->support->load($context, $type, $id, CrmPermission::Edit, anyStatus: true, lock: true);
            if ($record->getStatus() !== RecordStatus::Trashed) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $this->counter->take($context->tenant, $type);
            $record->restore($context->now, $context->actor->value);
            $this->support->activity($context, $type, $id, ActivityKind::Restored);
            $effects->event(new RecordRestored($context->tenant, $context->actor, $context->now, $type, $id));
            $this->support->audit($effects, $context, 'crm.' . $type->value . '.restored', $type, $id);
        });
    }

    /**
     * Purges a trashed record.
     */
    public function purge(SubjectType $type, string $id): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $type, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record = $this->support->load($context, $type, $id, CrmPermission::Manage, anyStatus: true, lock: true);
            if ($record->getStatus() !== RecordStatus::Trashed) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $this->purger->deleteRecord($context->tenant, $record);
            $effects->event(new RecordPurged($context->tenant, $context->actor, $context->now, $type, $id));
            $this->support->audit($effects, $context, 'crm.' . $type->value . '.purged', $type, $id);
        });
    }

    /**
     * Relates a contact to a company.
     */
    public function affiliate(string $contactId, string $companyId, ?string $role, bool $primary, ?bool $primaryContact = null, ?array $purposes = null): string
    {
        $context = $this->support->access->base();
        $role    = $role === null || trim($role) === '' ? null : trim($role);
        if ($role !== null && (mb_strlen($role) > 120 || RecordValidator::check($role, 120, 'text') !== null)) {
            throw InvalidInputException::field('role', 'error.invalid');
        }
        $purposes = $purposes === null ? null : $this->catalogue->checkPurposes($purposes);
        $write    = fn (Effects $effects): string => $this->writeAffiliation($effects, $context, $contactId, $companyId, $role, $primary, $primaryContact, $purposes);

        try {
            return $this->support->write($write);
        } catch (DuplicateRowException) {
            // Another writer stored the same pair at the same moment: run once more, now as an update of that row.
            return self::onceMore(fn (): string => $this->support->write($write));
        }
    }

    /**
     * Makes a contact the company's primary contact, or clears it.
     */
    public function setPrimaryContact(string $companyId, ?string $contactId): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $companyId, $contactId): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            // The lock order of affiliate(): the contact first, then the company.
            if ($contactId !== null) {
                $this->support->load($context, SubjectType::Contact, $contactId, CrmPermission::View, lock: true);
            }
            $company = $this->support->load($context, SubjectType::Company, $companyId, CrmPermission::Edit, lock: true);
            $rows    = $this->support->store->affiliations($context->tenant, SubjectType::Company, $companyId);
            if ($contactId !== null && array_filter($rows, static fn (Affiliation $a): bool => $a->getContactId() === $contactId) === []) {
                throw InvalidInputException::field('contact', 'error.not_affiliated');
            }
            if (!self::markPrimaryContact($rows, $contactId)) {
                return;
            }
            $company->touch($context->now, $context->actor->value);
            $this->support->activity($context, SubjectType::Company, $companyId, ActivityKind::PrimaryContact, ['contact' => $contactId]);
            $this->support->audit($effects, $context, 'crm.affiliation.primary_contact', SubjectType::Company, $companyId, ['contact' => $contactId]);
        });
    }

    /**
     * Replaces the party roles of a record.
     */
    public function setRoles(SubjectType $type, string $id, array $roles): void
    {
        $context = $this->support->access->base();
        $roles   = $this->catalogue->checkRoles($roles);
        $write   = function (Effects $effects) use ($context, $type, $id, $roles): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            // The record locked first: two role edits of one record run one after the other.
            $record            = $this->support->load($context, $type, $id, CrmPermission::Edit, lock: true);
            [$added, $removed] = $this->roles->set($context, $type, $id, $roles, $effects); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            if ($added !== [] || $removed !== []) {
                $record->touch($context->now, $context->actor->value);
            }
        };

        try {
            $this->support->write($write);
        } catch (DuplicateRowException) {
            // A writer that does not lock the record (a form edit, an import) stored the same role at the same moment:
            // once more, now that row is there.
            self::onceMore(fn () => $this->support->write($write));
        }
    }

    /**
     * Sets the primary-contact flag of a company's affiliations: only `$contactId`'s (none when null); whether a flag
     * changed. The caller holds the company's lock.
     *
     * @param list<Affiliation> $rows the company's affiliations
     */
    public static function markPrimaryContact(array $rows, ?string $contactId): bool
    {
        $changed = false;
        foreach ($rows as $row) {
            $wanted = $contactId !== null && $row->getContactId() === $contactId;
            if ($row->isPrimaryContact() !== $wanted) {
                $row->setPrimaryContact($wanted);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Removes an affiliation.
     */
    public function unaffiliate(string $affiliationId): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $affiliationId): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $store       = $this->support->store;
            $affiliation = RecordId::isValid($affiliationId) ? $store->affiliation($context->tenant, $affiliationId) : null;
            if (!$affiliation instanceof Affiliation) {
                throw new NotAvailableException();
            }
            $this->support->load($context, SubjectType::Contact, $affiliation->getContactId(), CrmPermission::Edit, lock: true)->touch($context->now, $context->actor->value);
            $store->remove($affiliation); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, SubjectType::Contact, $affiliation->getContactId(), ActivityKind::Unaffiliated, ['company' => $affiliation->getCompanyId()]);
            $this->support->activity($context, SubjectType::Company, $affiliation->getCompanyId(), ActivityKind::Unaffiliated, ['contact' => $affiliation->getContactId()]);
            $this->support->audit($effects, $context, 'crm.affiliation.removed', SubjectType::Contact, $affiliation->getContactId(), ['company' => $affiliation->getCompanyId()]);
        });
    }

    /**
     * @param list<string>|null $purposes
     */
    private function writeAffiliation(Effects $effects, CrmContext $context, string $contactId, string $companyId, ?string $role, bool $primary, ?bool $primaryContact, ?array $purposes): string
    {
        $store = $this->support->store;
        // Lock order: the contact, then the company (every path that locks both: imports, merges, primary contact).
        $contact  = $this->support->load($context, SubjectType::Contact, $contactId, CrmPermission::Edit, lock: true);
        $company  = $this->support->load($context, SubjectType::Company, $companyId, CrmPermission::View, lock: true);
        $existing = null;
        foreach ($store->affiliations($context->tenant, SubjectType::Contact, $contactId) as $affiliation) {
            if ($affiliation->getCompanyId() === $companyId) {
                $existing = $affiliation;
            } elseif ($primary && $affiliation->isPrimary()) {
                $affiliation->setPrimary(false);
            }
        }
        $created = !$existing instanceof Affiliation;
        $id      = $existing?->getId() ?? $this->support->id();
        if ($existing instanceof Affiliation) {
            $existing->change($role, $primary);
        } else {
            $existing = new Affiliation($id, $context->tenant->value, $contactId, $companyId, $role, $primary, $context->now);
            $store->add($existing); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($purposes !== null) {
            $existing->setPurposes($purposes);
        }
        $primaryChanged = false;
        if ($primaryContact !== null && $primaryContact !== $existing->isPrimaryContact()) {
            // The company's primary contact changes: an edit of the company, under its lock (taken above).
            $this->support->access->require($context, CrmPermission::Edit, SubjectRef::of(SubjectType::Company, $companyId, $company->getOwnerId()));
            $rows = $store->affiliations($context->tenant, SubjectType::Company, $companyId);
            if ($primaryContact) {
                self::markPrimaryContact($rows, $contactId);
            }
            $existing->setPrimaryContact($primaryContact);
            $company->touch($context->now, $context->actor->value);
            $this->support->activity($context, SubjectType::Company, $companyId, ActivityKind::PrimaryContact, ['contact' => $primaryContact ? $contactId : null]);
            $primaryChanged = true;
        }
        $contact->touch($context->now, $context->actor->value);
        $this->support->audit($effects, $context, 'crm.affiliation.saved', SubjectType::Contact, $contactId, ['company' => $companyId, 'primary' => $primary, 'primary_contact' => $existing->isPrimaryContact(), 'primary_contact_changed' => $primaryChanged, 'purposes' => implode(',', $existing->getPurposes())]);
        if ($created) {
            $this->support->activity($context, SubjectType::Contact, $contactId, ActivityKind::Affiliated, ['company' => $companyId]);
            $this->support->activity($context, SubjectType::Company, $companyId, ActivityKind::Affiliated, ['contact' => $contactId]);
        }

        return $id;
    }

    /**
     * The second run of a write whose unique key was refused once: a second refusal is the domain conflict
     * `crm_write_conflict` (409 without `X-Conflict-Code`), never a 500.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public static function onceMore(callable $write): mixed
    {
        try {
            return $write();
        } catch (DuplicateRowException $e) {
            throw new ConflictException(ConflictException::WRITE_CONFLICT, null, $e);
        }
    }

    /**
     * The owner of the input, checked against the directory's owners (an unknown owner is refused).
     *
     * @throws InvalidInputException
     */
    private function owner(CrmContext $context, RecordInput $input): ?string
    {
        if ($input->owner === null || $input->owner === '') {
            return null;
        }
        $owners = array_map(static fn (ActorProfile $p): string => $p->ref->value, $this->directory->owners($context->tenant));
        if (!in_array($input->owner, $owners, true)) {
            throw InvalidInputException::field('owner', 'error.owner_unknown');
        }

        return $input->owner;
    }

    /**
     * Maps a refused e-mail key to a form error naming the record that holds it: only when the input carries an e-mail
     * and another record (not `$id`, the one being edited) holds it. Any other refused key runs the write once more; a
     * second refusal is the domain conflict `crm_write_conflict` (409 without `X-Conflict-Code`: nothing committed, a
     * retry from the client is safe).
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function guardEmail(CrmContext $context, RecordInput $input, ?string $id, callable $write): mixed
    {
        $email = $input->type === SubjectType::Contact ? mb_strtolower(trim($input->values['email'] ?? ''), 'UTF-8') : '';
        for ($attempt = 1;; ++$attempt) {
            try {
                return $write();
            } catch (DuplicateRowException $e) {
                $other = $email === '' ? null : $this->support->read(fn (): ?CrmRecord => $this->support->store->contactByEmailKey($context->tenant, $email));
                if ($other instanceof CrmRecord && $other->getId() !== $id) {
                    throw InvalidInputException::field('email', 'error.email_taken', ['%id%' => $other->getId()]);
                }
                if ($attempt === 2) {
                    throw new ConflictException(ConflictException::WRITE_CONFLICT, null, $e);
                }
            }
        }
    }
}
