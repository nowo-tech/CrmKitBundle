<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\Shared\StaleRowException;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What every write service shares (contract section 15): the write unit with its after-commit effects, the record
 * loading rules (404 in another tenant, 409 for a merged or trashed record, the permission on the record), the
 * timeline and the audit. Stateless.
 *
 * @internal
 */
final readonly class WriteSupport
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        public CrmAccess $access,
        public CrmStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private CrmAuditSinkInterface $audit,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * A copy whose access gate is bound to `$caller`, for one call (0.3.0).
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->access->bind($caller), $this->store, $this->runner, $this->ids, $this->audit, $this->events);
    }

    /**
     * Runs `$work` in one write unit; releases its effects after the commit. A version that moved under the flush
     * becomes `crm_record_stale` (or `$staleCode`).
     *
     * @template T
     *
     * @param callable(Effects): T $work
     *
     * @return T
     */
    public function write(callable $work, string $staleCode = ConflictException::STALE): mixed
    {
        $effects = new Effects();
        $caller  = $this->access->caller();
        try {
            $result = $this->runner->write(static function () use ($work, $effects, $caller): mixed { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $effects->clear();
                $caller->beginUnit();
                $result = $work($effects);
                $caller->endUnit($result);

                return $result;
            });
        } catch (StaleRowException $e) {
            throw new ConflictException($staleCode, null, $e);
        }
        $effects->release($this->events, $this->audit);

        return $result;
    }

    /**
     * Runs a read on the bundle's entity manager (cleared afterwards).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function read(callable $work): mixed
    {
        return $this->runner->read($work);
    }

    /**
     * A new row id.
     */
    public function id(): string
    {
        return $this->ids->new();
    }

    /**
     * Loads a record of the tenant and checks the permission on it: absent ⇒ 404, then the permission (403), then a
     * merged record ⇒ 409 `crm_record_merged` (with the survivor) and, unless `$anyStatus`, a non-active one ⇒ 409
     * `crm_record_trashed`.
     */
    public function load(CrmContext $context, SubjectType $type, string $id, CrmPermission $permission, bool $anyStatus = false, bool $lock = false): CrmRecord
    {
        if (!RecordId::isValid($id)) {
            throw new NotAvailableException();
        }
        $record = $lock
            ? ($this->store->lockRecords($type, $context->tenant, [$id])[$id] ?? null)
            : $this->store->record($type, $context->tenant, $id);
        if (!$record instanceof CrmRecord) {
            throw new NotAvailableException();
        }
        $this->access->require($context, $permission, SubjectRef::of($type, $record->getId(), $record->getOwnerId()));
        if ($record->getStatus() === RecordStatus::Merged) {
            throw new ConflictException(ConflictException::MERGED, $record->getMergedIntoId());
        }
        if (!$anyStatus && !$record->isActive()) {
            throw new ConflictException(ConflictException::TRASHED, $record->getId());
        }

        return $record;
    }

    /**
     * Appends a timeline entry. **(0.3.0)** A write through the API adds `via` (the channel) and `token` (the token's
     * name) to the parameters, so the timeline reads "… via ‹token›".
     *
     * @param array<string, bool|int|string|null> $params
     */
    public function activity(CrmContext $context, SubjectType $type, string $id, ActivityKind $kind, array $params = []): void
    {
        if ($context->origin !== null) {
            $params['via']   = $context->origin->channel;
            $params['token'] = $context->origin->tokenName;
        }
        $this->store->add(new Activity($this->id(), $context->tenant->value, $type, $id, $kind, $context->actor->value, $params, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Keeps an audit entry for after the commit.
     *
     * @param array<string, bool|int|string|null> $details
     */
    public function audit(Effects $effects, CrmContext $context, string $action, ?SubjectType $type = null, ?string $id = null, array $details = []): void
    {
        $effects->audit(new AuditEntry($context->tenant, $context->actor, $action, $type?->value, $id, $details, $context->now, $context->origin));
    }
}
