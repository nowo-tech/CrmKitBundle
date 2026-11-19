<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;

/**
 * A deal (0.3.0): a title, a company and / or a contact, an owner, an amount, a probability override, an expected
 * close, its funnel stage and status, the lost reason, and the earliest due open next action. Versioned; the edit time
 * is kept to the microsecond.
 */
class Deal
{
    private ?string $amount = null;

    private ?string $currency = null;

    private ?int $probability = null;

    private ?DateTimeImmutable $expectedClose = null;

    private DealStatus $status = DealStatus::Open;

    private ?string $lostReason = null;

    private ?string $lostNote = null;

    private ?DateTimeImmutable $closedAt = null;

    private ?DateTimeImmutable $nextActionAt = null;

    private ?DateTimeImmutable $trashedAt = null;

    private ?DealStatus $trashedFrom = null;

    private int $version = 1;

    private DateTimeImmutable $updatedAt;

    private ?string $updatedById;

    private DateTimeImmutable $stageEnteredAt;

    /**
     * Wires the deal in its first stage.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $funnelId,
        private string $stageId,
        private string $title,
        private ?string $companyId,
        private ?string $contactId,
        private ?string $ownerId,
        private DateTimeImmutable $createdAt,
        private ?string $createdById,
    ) {
        $this->updatedAt      = $createdAt;
        $this->updatedById    = $createdById;
        $this->stageEnteredAt = $createdAt;
    }

    /** The row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** The funnel. */
    public function getFunnelId(): string
    {
        return $this->funnelId;
    }

    /** The current stage. */
    public function getStageId(): string
    {
        return $this->stageId;
    }

    /** The title (untrusted). */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** The company, or null. */
    public function getCompanyId(): ?string
    {
        return $this->companyId;
    }

    /** The contact, or null. */
    public function getContactId(): ?string
    {
        return $this->contactId;
    }

    /** The owner, or null. */
    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    /** The amount, or null. */
    public function getAmount(): ?Money
    {
        return $this->amount === null || $this->currency === null ? null : new Money(Money::normalise($this->amount), $this->currency);
    }

    /** The probability override (0..100), or null (the stage's). */
    public function getProbability(): ?int
    {
        return $this->probability;
    }

    /** The expected close, or null. */
    public function getExpectedClose(): ?DateImmutable
    {
        return $this->expectedClose instanceof DateTimeImmutable ? new DateImmutable($this->expectedClose->format('Y-m-d')) : null;
    }

    /** Open, won, lost or trashed. */
    public function getStatus(): DealStatus
    {
        return $this->status;
    }

    /** Whether it is in the trash. */
    public function isTrashed(): bool
    {
        return $this->status === DealStatus::Trashed;
    }

    /** The lost reason key, or null. */
    public function getLostReason(): ?string
    {
        return $this->lostReason;
    }

    /** The lost note (untrusted), or null. */
    public function getLostNote(): ?string
    {
        return $this->lostNote;
    }

    /** When it entered its current stage. */
    public function getStageEnteredAt(): DateTimeImmutable
    {
        return $this->stageEnteredAt;
    }

    /** When it was won or lost, or null. */
    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** The earliest due open next action, or null. */
    public function getNextActionAt(): ?DateTimeImmutable
    {
        return $this->nextActionAt;
    }

    /** When it was trashed, or null. */
    public function getTrashedAt(): ?DateTimeImmutable
    {
        return $this->trashedAt;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Who created it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    /** When it last changed (microseconds). */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Who changed it last. */
    public function getUpdatedById(): ?string
    {
        return $this->updatedById;
    }

    /** Changes the title. */
    public function rename(string $title): void
    {
        $this->title = $title;
    }

    /** Changes the company and the contact. */
    public function relate(?string $companyId, ?string $contactId): void
    {
        $this->companyId = $companyId;
        $this->contactId = $contactId;
    }

    /** Changes the owner. */
    public function changeOwner(?string $ownerId): void
    {
        $this->ownerId = $ownerId;
    }

    /** Changes the amount (null clears it). */
    public function changeAmount(?Money $amount): void
    {
        $this->amount   = $amount?->amount;
        $this->currency = $amount?->currency;
    }

    /** Changes the probability override. */
    public function changeProbability(?int $probability): void
    {
        $this->probability = $probability;
    }

    /** Changes the expected close. */
    public function changeExpectedClose(?DateImmutable $date): void
    {
        $this->expectedClose = $date?->toDateTime();
    }

    /**
     * Moves it to a stage: a won / lost status closes it (with the reason of a loss), open reopens it.
     */
    public function moveTo(string $stageId, DealStatus $status, ?string $lostReason, ?string $lostNote, DateTimeImmutable $now): void
    {
        $this->stageId        = $stageId;
        $this->stageEnteredAt = $now;
        $this->status         = $status;
        $this->lostReason     = $status === DealStatus::Lost ? $lostReason : null;
        $this->lostNote       = $status === DealStatus::Lost ? $lostNote : null;
        $this->closedAt       = $status === DealStatus::Open ? null : $now;
    }

    /** Sets the earliest due open next action. */
    public function changeNextActionAt(?DateTimeImmutable $at): void
    {
        $this->nextActionAt = $at;
    }

    /** Moves it to the trash (its status is kept for the restore). */
    public function trash(DateTimeImmutable $now, ?string $actorId): void
    {
        $this->trashedFrom = $this->status;
        $this->status      = DealStatus::Trashed;
        $this->trashedAt   = $now;
        $this->touch($now, $actorId);
    }

    /** Brings it back from the trash with its former status. */
    public function restore(DateTimeImmutable $now, ?string $actorId): void
    {
        $this->status      = $this->trashedFrom ?? DealStatus::Open;
        $this->trashedFrom = null;
        $this->trashedAt   = null;
        $this->touch($now, $actorId);
    }

    /** Marks a change (moves the version at the flush). */
    public function touch(DateTimeImmutable $now, ?string $actorId): void
    {
        $this->updatedAt   = $now;
        $this->updatedById = $actorId;
    }

    /** Forgets a contact (GDPR erasure). */
    public function unlinkContact(): void
    {
        $this->contactId = null;
    }

    /** Forgets a company (purge of the company). */
    public function unlinkCompany(): void
    {
        $this->companyId = null;
    }
}
