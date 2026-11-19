<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiAffiliationSpec;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\RecordService;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_map;
use function array_slice;
use function is_string;

/**
 * Company and contact writes of the API (0.3.0, design §6.2, §6.5): create with the duplicate check first, update
 * with a required base version, trash. Every write needs an idempotency key and is audited with the token. Stateless.
 */
final readonly class CrmApiRecordOperations
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmApiGuard $guard,
        private CrmApiWriter $writer,
        private CrmApiRecords $records,
        private CrmApiReadOperations $reads,
        private RecordService $service,
        private CrmReader $reader,
        private WriteSupport $support,
        private CrmApiRelationOperations $relations,
    ) {
    }

    /**
     * `crm_company_create`. A visible match (tax id, else domain, else name without tax id and website) is returned
     * with `duplicate: true` and nothing is created. Codes: `CRM_003`, `CRM_004`, `CRM_005`, `CRM_006`, `INPUT_00x`.
     */
    public function createCompany(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, CrmApiRecordDraft $draft): CrmApiWritten
    {
        return $this->create(CrmApiOperation::CompanyCreate, $principal, $tenant, $key, $draft);
    }

    /**
     * `crm_contact_create`. A contact with the same e-mail is returned with `duplicate: true` (a trashed one is
     * `CRM_003`, a hidden one `CRM_006`); `company` affiliates the new contact.
     */
    public function createContact(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, CrmApiRecordDraft $draft): CrmApiWritten
    {
        return $this->create(CrmApiOperation::ContactCreate, $principal, $tenant, $key, $draft);
    }

    /**
     * `crm_company_update`: partial; `$base` must be the current version (`CRM_001` with `current_version`).
     */
    public function updateCompany(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $id, RecordVersion $base, CrmApiRecordChanges $changes): CrmApiWritten
    {
        return $this->update(CrmApiOperation::CompanyUpdate, SubjectType::Company, $principal, $tenant, $key, $id, $base, $changes);
    }

    /**
     * `crm_contact_update`: as {@see self::updateCompany()}.
     */
    public function updateContact(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $id, RecordVersion $base, CrmApiRecordChanges $changes): CrmApiWritten
    {
        return $this->update(CrmApiOperation::ContactUpdate, SubjectType::Contact, $principal, $tenant, $key, $id, $base, $changes);
    }

    /**
     * `crm_record_trash`: one company or contact, restorable from the pages; destructive (needs the tick).
     */
    public function trash(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, SubjectType $type, RecordId $id, ?RecordVersion $base): CrmApiWritten
    {
        if ($type !== SubjectType::Company && $type !== SubjectType::Contact) {
            throw CrmApiRefusal::malformed('type');
        }
        $call    = $this->guard->call($principal, $tenant, CrmApiOperation::RecordTrash);
        $trashed = new CrmApiItem('trashed', ['type' => $type->value, 'id' => $id->value, 'status' => 'trashed']);

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::RecordTrash,
            ['type' => $type->value, 'id' => $id->value, 'base' => $base?->value],
            200,
            static fn (): array => ['record' => $id->value],
            function (CrmApiCall $call) use ($type, $id, $base, $trashed): CrmApiWritten {
                $subject = $this->records->probe($call, $type, $id);
                $call->require(CrmApiCapability::Trash, $subject);
                $version = $base instanceof RecordVersion ? $base->value : ($this->records->version($call, $type, $id->value) ?? 0);
                CrmApiErrors::run(fn () => $this->service->as($call->caller)->trash($type, $id->value, $version), $this->stale($call, $type, $id->value));

                return new CrmApiWritten($trashed, false, true);
            },
            static fn (CrmApiReplay $r): CrmApiWritten => new CrmApiWritten($trashed, true, true, $r->status),
        );
    }

    private function create(CrmApiOperation $operation, CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, CrmApiRecordDraft $draft): CrmApiWritten
    {
        $type = $operation === CrmApiOperation::CompanyCreate ? SubjectType::Company : SubjectType::Contact;
        if ($draft->type !== $type || ($type === SubjectType::Company && $draft->company !== null)) {
            throw CrmApiRefusal::malformed('type');
        }
        $call = $this->guard->call($principal, $tenant, $operation);

        return $this->writer->write(
            $call,
            $key,
            $operation,
            $draft->canonical(),
            201,
            static fn (mixed $id): array => ['record' => is_string($id) ? $id : ''],
            function (CrmApiCall $bound) use ($call, $type, $draft): CrmApiWritten {
                $bound->require(CrmApiCapability::Create);
                if ($draft->company !== null) {
                    // Affiliating needs `edit`; the company must be one the principal sees.
                    $bound->require(CrmApiCapability::Edit);
                    $this->records->probe($bound, SubjectType::Company, $draft->company->companyId);
                }
                $input = new RecordInput($type, $draft->values, $draft->owner->resolve($bound->principal->actor), $draft->custom, $draft->tagIds, true, $draft->roles);
                try {
                    $id = CrmApiErrors::run(fn (): string => $this->service->as($bound->caller)->createChecked($input, fn (CrmRecord $record) => $this->duplicates($bound, $record)));
                } catch (CrmApiDuplicate $duplicate) {
                    return new CrmApiWritten($this->created($call, $type, $duplicate->recordId, $duplicate->key, []), false, false, 200);
                }
                $this->affiliateNew($call, $id, $draft);

                return new CrmApiWritten($this->created($call, $type, $id, null, $this->hints($call, $type, $id)), false, true, 201);
            },
            function (CrmApiReplay $r) use ($call, $type, $draft): CrmApiWritten {
                $id = $r->ids['record'] ?? '';
                $this->records->probe($call, $type, RecordId::isValid($id) ? new RecordId($id) : throw CrmApiRefusal::notFound());
                // The affiliation is a second unit: a replay repeats it (set semantics), so it ends done.
                $this->affiliateNew($call, $id, $draft);

                return new CrmApiWritten($this->created($call, $type, $id, null, []), true, true, $r->status);
            },
        );
    }

    private function update(CrmApiOperation $operation, SubjectType $type, CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $id, RecordVersion $base, CrmApiRecordChanges $changes): CrmApiWritten
    {
        if ($changes->type !== $type) {
            throw CrmApiRefusal::malformed('type');
        }
        $call = $this->guard->call($principal, $tenant, $operation);

        return $this->writer->write(
            $call,
            $key,
            $operation,
            ['id' => $id->value, 'base' => $base->value] + $changes->canonical(),
            200,
            static fn (): array => ['record' => $id->value],
            function (CrmApiCall $call) use ($type, $id, $base, $changes): CrmApiWritten {
                $subject = $this->records->probe($call, $type, $id);
                $call->require(CrmApiCapability::Edit, $subject);
                $input   = new RecordInput($type, $changes->values, $changes->owner?->resolve($call->principal->actor), $changes->custom, $changes->tagIds, $changes->owner !== null, $changes->roles);
                $version = CrmApiErrors::run(fn (): int => $this->service->as($call->caller)->update($id->value, $input, $base->value), $this->stale($call, $type, $id->value));

                return new CrmApiWritten($this->reads->present($call, $type, $id->value), false, $version !== $base->value);
            },
            function (CrmApiReplay $r) use ($call, $type, $id): CrmApiWritten {
                $this->records->probe($call, $type, $id);

                return new CrmApiWritten($this->reads->present($call, $type, $id->value), true, true, $r->status);
            },
        );
    }

    /**
     * The duplicate check of a create, inside its write unit (design §6.5): the first rule that matches decides;
     * the oldest visible active match is returned, a visible trashed one is `CRM_003`, an invisible one `CRM_006`.
     */
    private function duplicates(CrmApiCall $call, CrmRecord $record): void
    {
        $rules = [];
        if ($record instanceof Contact) {
            $rules = $record->getEmailKey() === null ? [] : [['email', 'emailKey', $record->getEmailKey()]];
        } elseif ($record instanceof Company) {
            if ($record->getTaxIdKey() !== null) {
                $rules[] = ['tax_id', 'taxIdKey', $record->getTaxIdKey()];
            }
            if ($record->getDomainKey() !== null) {
                $rules[] = ['domain', 'domainKey', $record->getDomainKey()];
            }
            if ($rules === []) {
                $rules[] = ['name', 'nameKey', $record->getNameKey()];
            }
        }
        $support = $this->support->as($call->caller);
        foreach ($rules as [$name, $field, $value]) {
            $matches = $support->store->matching($record::type(), $call->context->tenant, $field, $value, true);
            if ($matches === []) {
                continue;
            }
            $trashed = null;
            foreach ($matches as $match) {
                if (!$call->caller->isGranted(CrmPermission::View, $call->context->tenant, SubjectRef::of($record::type(), $match->getId(), $match->getOwnerId()))) {
                    continue;
                }
                if ($match->getStatus() === RecordStatus::Active) {
                    throw new CrmApiDuplicate($match->getId(), $name);
                }
                $trashed ??= $match->getId();
            }

            throw $trashed !== null ? CrmApiRefusal::of(CrmApiCode::TRASHED, ['record_id' => $trashed]) : CrmApiRefusal::of(CrmApiCode::HIDDEN_DUPLICATE);
        }
    }

    /**
     * Affiliates a new contact to the draft's company (a second write unit, set semantics).
     */
    private function affiliateNew(CrmApiCall $call, string $contactId, CrmApiRecordDraft $draft): void
    {
        if ($draft->company === null) {
            return;
        }
        $this->relations->apply($call, new CrmApiAffiliationSpec(new RecordId($contactId), $draft->company));
    }

    /**
     * Up to 5 visible name matches of a new record (ids only).
     *
     * @return list<string>
     */
    private function hints(CrmApiCall $call, SubjectType $type, string $id): array
    {
        $views = CrmApiErrors::run(fn (): array => $this->reader->as($call->caller)->duplicates($type, $id));

        return array_map(static fn (RecordView $v): string => $v->id, array_slice($views, 0, 5));
    }

    /**
     * The answer of a create.
     *
     * @param list<string> $hints
     */
    private function created(CrmApiCall $call, SubjectType $type, string $id, ?string $duplicateOf, array $hints): CrmApiItem
    {
        return new CrmApiItem('created', [
            'record'              => $this->reads->present($call, $type, $id),
            'duplicate'           => $duplicateOf !== null,
            'duplicate_of'        => $duplicateOf,
            'possible_duplicates' => $hints,
        ]);
    }

    /**
     * The extras of a stale version: the current one.
     *
     * @return callable(ConflictException): array<string, int|string|null>
     */
    private function stale(CrmApiCall $call, SubjectType $type, string $id): callable
    {
        return fn (ConflictException $e): array => $e->errorCode === ConflictException::STALE ? ['current_version' => $this->records->version($call, $type, $id)] : [];
    }
}
