<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

/**
 * Step 1 of design §4.5 for one company or contact: missing, in another tenant, trashed, erased or not viewable by the
 * principal (scoping, or a `RECORD_ACCESS_CHECK` deny of `view`) all answer 404 `ACCESS_001`; a merged record answers
 * 409 `CRM_002` (the survivor's id only when the principal may view it) or, for reads, a tombstone. Stateless.
 *
 * @internal
 */
final readonly class CrmApiRecords
{
    /**
     * Wires the write support (bound per call).
     */
    public function __construct(private WriteSupport $support)
    {
    }

    /**
     * The record's subject after the reach checks; a merged record ⇒ `CRM_002` unless `$tombstone`, then null with
     * `$merged` filled.
     *
     * @param array{id: ?string, version: int}|null $merged
     *
     * @param-out array{id: ?string, version: int}|null $merged
     */
    public function probe(CrmApiCall $call, SubjectType $type, RecordId $id, bool $tombstone = false, ?array &$merged = null): ?SubjectRef
    {
        $support = $this->support->as($call->caller);
        $record  = $support->read(static fn (): ?CrmRecord => $support->store->record($type, $call->context->tenant, $id->value));
        if (!$record instanceof CrmRecord || $record->getStatus() === RecordStatus::Trashed || $record->getStatus() === RecordStatus::Erased) {
            throw CrmApiRefusal::notFound();
        }
        $subject = SubjectRef::of($type, $record->getId(), $record->getOwnerId());
        if (!$call->caller->isGranted(CrmPermission::View, $call->context->tenant, $subject)) {
            throw CrmApiRefusal::notFound();
        }
        if ($record->getStatus() === RecordStatus::Merged) {
            $survivor = $this->visibleSurvivor($call, $type, $record->getMergedIntoId());
            if ($tombstone) {
                $merged = ['id' => $survivor, 'version' => $record->getVersion()];

                return null;
            }

            throw CrmApiRefusal::of(CrmApiCode::MERGED, $survivor === null ? [] : ['merged_into_id' => $survivor]);
        }

        return $subject;
    }

    /**
     * The id of a record when the principal may view it (an active one), else null.
     */
    public function visibleId(CrmApiCall $call, SubjectType $type, ?string $id): ?string
    {
        if ($id === null || !RecordId::isValid($id)) {
            return null;
        }
        $support = $this->support->as($call->caller);
        $record  = $support->read(static fn (): ?CrmRecord => $support->store->record($type, $call->context->tenant, $id));

        return $record instanceof CrmRecord && $record->getStatus() !== RecordStatus::Erased && $record->getStatus() !== RecordStatus::Merged
            && $call->caller->isGranted(CrmPermission::View, $call->context->tenant, SubjectRef::of($type, $record->getId(), $record->getOwnerId())) ? $record->getId() : null;
    }

    /**
     * The current version of a record (for `CRM_001`), or null.
     */
    public function version(CrmApiCall $call, SubjectType $type, string $id): ?int
    {
        $support = $this->support->as($call->caller);

        return $support->read(static fn (): ?int => $support->store->record($type, $call->context->tenant, $id)?->getVersion());
    }

    private function visibleSurvivor(CrmApiCall $call, SubjectType $type, ?string $id): ?string
    {
        // Follow at most a few merges: the survivor may itself have been merged since.
        for ($hops = 0; $hops < 5 && $id !== null; ++$hops) {
            $support = $this->support->as($call->caller);
            $record  = RecordId::isValid($id) ? $support->read(static fn (): ?CrmRecord => $support->store->record($type, $call->context->tenant, $id)) : null;
            if (!$record instanceof CrmRecord) {
                return null;
            }
            if ($record->getStatus() !== RecordStatus::Merged) {
                return $this->visibleId($call, $type, $record->getId());
            }
            $id = $record->getMergedIntoId();
        }

        return null;
    }
}
