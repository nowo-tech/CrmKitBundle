<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\PlanCounter;
use Nowo\CrmKitBundle\Application\RecordValidator;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function array_map;
use function implode;
use function in_array;
use function max;
use function trim;

/**
 * Deals (0.3.0): every write is one write unit. A create takes the `deals` plan counter first (its row lock serialises
 * the creates of a tenant), then re-reads the funnel, the stage and the company / contact; every change locks the deal
 * `FOR UPDATE`, compares the version when one is given, and a stage move writes its stage change with the seconds spent
 * in the stage left, computed under that lock. Amounts and the probability override need `amounts_view`. A trashed
 * deal is restorable for `trash_days`, then purged. Stateless.
 */
final readonly class DealService
{
    /** Longest title. */
    public const MAX_TITLE = 200;

    /** Longest lost note. */
    public const MAX_LOST_NOTE = 500;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private PipelineSupport $pipeline,
        private PlanCounter $counter,
        private CrmActorDirectoryInterface $directory,
        private PipelineHousekeeping $housekeeping,
    ) {
    }

    /**
     * A copy bound to `$caller` for one call (an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->pipeline->as($caller), $this->counter, $this->directory, $this->housekeeping);
    }

    /**
     * Creates a deal in an open stage of a deal funnel (`$input->stageId`, or the funnel's first open stage) with a
     * company and / or a contact the actor may view. The new deal's id.
     *
     * @throws AccessDeniedException `create` (or `amounts_view` for amounts / probability)
     * @throws InvalidInputException title, company_id / contact_id, owner, funnel_id
     * @throws ConflictException `crm_stage_invalid` (archived funnel, closed or foreign stage), `crm_limit_reached`
     * @throws NotAvailableException no tenant, CRM off, unknown funnel
     */
    public function create(DealInput $input): string
    {
        $context = $this->pipeline->support->access->context(CrmPermission::Create);
        $title   = $this->title($input->title ?? '');
        if ($input->companyId === null && $input->contactId === null) {
            throw InvalidInputException::field('company_id', 'error.deal_party_required');
        }
        $this->amountsAllowed($context, $input, true);
        $owner = $this->owner($context, $input->ownerId);

        return $this->pipeline->support->write(function (Effects $effects) use ($context, $input, $title, $owner): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $store = $this->pipeline->store;
            $this->counter->take($context->tenant, SubjectType::Deal, static fn (): int => $store->countLiveDeals($context->tenant));
            $funnel = $this->pipeline->funnel($context, $input->funnelId ?? '');
            PipelineSupport::kind($funnel, FunnelKind::Deal);
            $view  = PipelineSupport::view($funnel, $this->pipeline->stages($context, $funnel->getId()));
            $stage = $input->stageId === null ? $view->firstOpenStage() : $view->stage($input->stageId);
            if ($funnel->isArchived() || !$stage instanceof StageView || $stage->archived || $stage->kind !== StageKind::Open) {
                throw new ConflictException(ConflictException::STAGE_INVALID);
            }
            $companyId = $input->companyId === null ? null : $this->pipeline->related($context, SubjectType::Company, $input->companyId)->getId();
            $contactId = $input->contactId === null ? null : $this->pipeline->related($context, SubjectType::Contact, $input->contactId)->getId();
            $id        = $this->pipeline->support->id();
            $deal      = new Deal($id, $context->tenant->value, $funnel->getId(), $stage->id, $title, $companyId, $contactId, $owner, $context->now, $context->actor->value);
            $deal->changeAmount($input->amount);
            $deal->changeProbability($input->probability);
            $deal->changeExpectedClose($input->expectedClose);
            $this->pipeline->support->store->add($deal); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->change($context, $deal, null, $stage->id, null);
            $this->pipeline->support->activity($context, SubjectType::Deal, $id, ActivityKind::Created);
            $this->pipeline->support->audit($effects, $context, 'crm.deal.created', SubjectType::Deal, $id, ['funnel' => $funnel->getId(), 'stage' => $stage->id]);

            return $id;
        });
    }

    /**
     * Changes the fields of `$input->fields` (title, company, contact, owner, amount, probability, expected close) of a
     * deal at `$expectedVersion`; the new version (the same one when nothing changed).
     *
     * @throws NotAvailableException missing, trashed, other tenant, or not visible
     * @throws AccessDeniedException `edit` on the deal (or `amounts_view`)
     * @throws ConflictException `crm_record_stale`
     * @throws InvalidInputException
     */
    public function update(string $id, DealInput $input, int $expectedVersion): int
    {
        $context = $this->pipeline->support->access->base();
        $title   = $input->has('title') ? $this->title($input->title ?? '') : null;
        $owner   = $input->has('owner') ? $this->owner($context, $input->ownerId) : null;

        return $this->pipeline->support->write(function (Effects $effects) use ($context, $id, $input, $title, $owner, $expectedVersion): int { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->pipeline->deal($context, $id, CrmPermission::Edit, true);
            if ($deal->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $this->amountsAllowed($context, $input, false);
            $changed = [];
            if ($title !== null && $title !== $deal->getTitle()) {
                $deal->rename($title);
                $changed[] = 'title';
            }
            $companyId = $input->has('company_id') ? $input->companyId : $deal->getCompanyId();
            $contactId = $input->has('contact_id') ? $input->contactId : $deal->getContactId();
            if ($companyId !== $deal->getCompanyId() || $contactId !== $deal->getContactId()) {
                if ($companyId === null && $contactId === null) {
                    throw InvalidInputException::field('company_id', 'error.deal_party_required');
                }
                $companyId = $companyId === null || $companyId === $deal->getCompanyId() ? $companyId : $this->pipeline->related($context, SubjectType::Company, $companyId)->getId();
                $contactId = $contactId === null || $contactId === $deal->getContactId() ? $contactId : $this->pipeline->related($context, SubjectType::Contact, $contactId)->getId();
                $changed   = [...$changed, ...($companyId !== $deal->getCompanyId() ? ['company_id'] : []), ...($contactId !== $deal->getContactId() ? ['contact_id'] : [])];
                $deal->relate($companyId, $contactId);
            }
            if ($input->has('owner') && $owner !== $deal->getOwnerId()) {
                $deal->changeOwner($owner);
                $changed[] = 'owner';
            }
            $amount = $deal->getAmount();
            if ($input->has('amount') && !($input->amount instanceof Money ? $amount instanceof Money && $amount->equals($input->amount) : !$amount instanceof Money)) {
                $deal->changeAmount($input->amount);
                $changed[] = 'amount';
            }
            if ($input->has('probability') && $input->probability !== $deal->getProbability()) {
                $deal->changeProbability($input->probability);
                $changed[] = 'probability';
            }
            if ($input->has('expected_close') && $input->expectedClose?->value !== $deal->getExpectedClose()?->value) {
                $deal->changeExpectedClose($input->expectedClose);
                $changed[] = 'expected_close';
            }
            if ($changed === []) {
                return $deal->getVersion();
            }
            $deal->touch($context->now, $context->actor->value);
            $this->pipeline->support->store->flush();
            $this->pipeline->support->activity($context, SubjectType::Deal, $id, ActivityKind::Updated, ['fields' => implode(',', $changed)]);
            $this->pipeline->support->audit($effects, $context, 'crm.deal.updated', SubjectType::Deal, $id, ['fields' => implode(',', $changed)]);

            return $deal->getVersion();
        });
    }

    /**
     * Moves a deal to a stage of its funnel: a lost stage needs a lost reason (a key of `pipeline.lost_reasons`) and
     * takes an optional note (≤ 500, untrusted); won and lost close the deal, an open stage reopens it; the same stage
     * changes nothing (false). `$expectedVersion` is optional. Whether the deal moved.
     *
     * @throws AccessDeniedException|NotAvailableException
     * @throws ConflictException `crm_record_stale`, `crm_stage_invalid` (a stage of another funnel, archived)
     * @throws InvalidInputException `lost_reason` (`error.lost_reason_required`, `error.lost_reason_unknown`), `lost_note`
     */
    public function moveStage(string $id, string $stageId, ?string $lostReason = null, ?string $lostNote = null, ?int $expectedVersion = null): bool
    {
        $context  = $this->pipeline->support->access->base();
        $lostNote = $lostNote === null || trim($lostNote) === '' ? null : trim($lostNote);
        if ($lostNote !== null && RecordValidator::check($lostNote, self::MAX_LOST_NOTE, 'textarea') !== null) {
            throw InvalidInputException::field('lost_note', 'error.invalid');
        }

        return $this->pipeline->support->write(function (Effects $effects) use ($context, $id, $stageId, $lostReason, $lostNote, $expectedVersion): bool { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->pipeline->deal($context, $id, CrmPermission::Edit, true);
            if ($expectedVersion !== null && $deal->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $stage  = RecordId::isValid($stageId) ? $this->pipeline->store->stage($context->tenant, $stageId) : null;
            $funnel = $this->pipeline->funnel($context, $deal->getFunnelId());
            if (!$stage instanceof Stage || $stage->getFunnelId() !== $deal->getFunnelId() || $stage->isArchived() || $funnel->isArchived()) {
                throw new ConflictException(ConflictException::STAGE_INVALID);
            }
            if ($stage->getId() === $deal->getStageId()) {
                return false;
            }
            if ($stage->getKind() === StageKind::Lost) {
                if ($lostReason === null || $lostReason === '') {
                    throw InvalidInputException::field('lost_reason', 'error.lost_reason_required');
                }
                if (!in_array($lostReason, $this->pipeline->lostReasons, true)) {
                    throw InvalidInputException::field('lost_reason', 'error.lost_reason_unknown');
                }
            }
            $from = $deal->getStageId();
            $this->change($context, $deal, $from, $stage->getId(), max(0, $context->now->getTimestamp() - $deal->getStageEnteredAt()->getTimestamp()));
            $deal->moveTo($stage->getId(), DealStatus::of($stage->getKind()), $lostReason, $lostNote, $context->now);
            $deal->touch($context->now, $context->actor->value);
            $this->pipeline->support->activity($context, SubjectType::Deal, $id, ActivityKind::StageMoved, ['from' => $from, 'to' => $stage->getId()]);
            $this->pipeline->support->audit($effects, $context, 'crm.deal.stage_moved', SubjectType::Deal, $id, ['from' => $from, 'to' => $stage->getId(), 'kind' => $stage->getKind()->value]);

            return true;
        });
    }

    /**
     * Moves a deal to the trash (restorable from the pages for `trash_days`, then purged). `$expectedVersion` is
     * optional.
     *
     * @throws AccessDeniedException|NotAvailableException `delete`
     * @throws ConflictException `crm_record_stale`
     */
    public function trash(string $id, ?int $expectedVersion = null): void
    {
        $context = $this->pipeline->support->access->base();
        $this->pipeline->support->write(function (Effects $effects) use ($context, $id, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->pipeline->deal($context, $id, CrmPermission::Delete, true);
            if ($expectedVersion !== null && $deal->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $deal->trash($context->now, $context->actor->value);
            $this->counter->release($context->tenant, SubjectType::Deal);
            $this->pipeline->support->activity($context, SubjectType::Deal, $id, ActivityKind::Trashed);
            $this->pipeline->support->audit($effects, $context, 'crm.deal.trashed', SubjectType::Deal, $id);
        });
    }

    /**
     * Brings a deal back from the trash with its former status (counts against the plan limit again).
     *
     * @throws AccessDeniedException|NotAvailableException `delete`
     * @throws ConflictException `crm_record_stale` (not in the trash), `crm_limit_reached`
     */
    public function restore(string $id): void
    {
        $context = $this->pipeline->support->access->base();
        $this->pipeline->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->pipeline->deal($context, $id, CrmPermission::Delete, true, true);
            if (!$deal->isTrashed()) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $this->counter->take($context->tenant, SubjectType::Deal, fn (): int => $this->pipeline->store->countLiveDeals($context->tenant));
            $deal->restore($context->now, $context->actor->value);
            $this->pipeline->support->activity($context, SubjectType::Deal, $id, ActivityKind::Restored);
            $this->pipeline->support->audit($effects, $context, 'crm.deal.restored', SubjectType::Deal, $id);
        });
    }

    /**
     * Deletes a trashed deal for good, with its stage history, next actions and timeline (`manage`).
     *
     * @throws AccessDeniedException|NotAvailableException
     * @throws ConflictException `crm_record_stale` (not in the trash)
     */
    public function purge(string $id): void
    {
        $context = $this->pipeline->support->access->base();
        $this->pipeline->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal = $this->pipeline->deal($context, $id, CrmPermission::Manage, true, true);
            if (!$deal->isTrashed()) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $this->housekeeping->deleteDeal($context->tenant, $deal);
            $this->pipeline->support->audit($effects, $context, 'crm.deal.purged', SubjectType::Deal, $id);
        });
    }

    /**
     * Writes the stage change of a deal (insert only).
     */
    private function change(CrmContext $context, Deal $deal, ?string $from, string $to, ?int $seconds): void
    {
        $via = $context->origin?->channel;
        $this->pipeline->support->store->add(new StageChange($this->pipeline->support->id(), $context->tenant->value, SubjectType::Deal, $deal->getId(), $deal->getFunnelId(), $from, $to, $context->actor->value, $via, $context->now, $seconds)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Amounts and the probability override need `amounts_view` (on a change: only the fields sent with a value or
     * cleared).
     *
     * @throws AccessDeniedException
     */
    private function amountsAllowed(CrmContext $context, DealInput $input, bool $creating): void
    {
        $sends = $creating ? ($input->amount instanceof Money || $input->probability !== null) : ($input->has('amount') || $input->has('probability'));
        if ($sends) {
            $this->pipeline->support->access->require($context, CrmPermission::AmountsView);
        }
    }

    /**
     * The owner, checked against the directory (an unknown owner is refused); null: nobody.
     *
     * @throws InvalidInputException
     */
    private function owner(CrmContext $context, ?string $owner): ?string
    {
        if ($owner === null || $owner === '') {
            return null;
        }
        $owners = array_map(static fn (ActorProfile $p): string => $p->ref->value, $this->pipeline->support->access->owners($context, $this->directory));
        if (ActorRef::isReserved($owner) || !in_array($owner, $owners, true)) {
            throw InvalidInputException::field('owner', 'error.owner_unknown');
        }

        return $owner;
    }

    /**
     * A trimmed title of 1–200 characters (untrusted text).
     *
     * @throws InvalidInputException
     */
    private function title(string $title): string
    {
        $title = trim($title);
        $error = $title === '' ? 'error.required' : RecordValidator::check($title, self::MAX_TITLE, 'text');
        if ($error !== null) {
            throw InvalidInputException::field('title', $error);
        }

        return $title;
    }
}
