<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A CSV, vCard or **(0.10.0)** HubSpot import (contract section 15.4). Claimed by a conditional UPDATE; counters move
 * atomically in SQL. A HubSpot import also keeps its connection, its run cursor (stage, HubSpot paging token, page,
 * owner map: ids only), when it may run again after a pause (`resume_after`) and why it paused or failed.
 */
class Import
{
    private ImportStatus $status = ImportStatus::Preview;

    private DuplicatePolicy $duplicatePolicy = DuplicatePolicy::Skip;

    /** @var array<string, string> column => field */
    private array $mapping;

    private int $rowsDone = 0;

    private int $createdCount = 0;

    private int $updatedCount = 0;

    private int $duplicateCount = 0;

    private int $invalidCount = 0;

    private int $limitCount = 0;

    private ?string $claimToken = null;

    private ?DateTimeImmutable $claimedAt = null;

    private ?DateTimeImmutable $finishedAt = null;

    private int $version = 1;

    /** @var array<string, mixed>|null */
    private ?array $cursor = null;

    private ?DateTimeImmutable $resumeAfter = null;

    private ?string $errorCode = null;

    /**
     * @param list<string> $columns the header columns of the file
     * @param array<string, string> $mapping detected column => field
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private ImportFormat $format,
        private string $fileName,
        private array $columns,
        array $mapping,
        private int $rowsTotal,
        private ?string $createdById,
        private DateTimeImmutable $createdAt,
        private ?string $connectionId = null,
    ) {
        $this->mapping = $mapping;
    }

    /**
     * The first run cursor of a HubSpot import.
     *
     * @param array<string, mixed> $cursor
     */
    public function startCursor(array $cursor): void
    {
        $this->cursor = $cursor;
    }

    /**
     * The duplicate policy chosen before the confirm (HubSpot's start step); the confirm sets the final one.
     */
    public function choosePolicy(DuplicatePolicy $policy): void
    {
        $this->duplicatePolicy = $policy;
    }

    /**
     * A pause with its time and reason (the store writes it with one conditional UPDATE; this mirrors it in memory).
     */
    public function pause(?DateTimeImmutable $resumeAfter, ?string $errorCode): void
    {
        $this->claimToken  = null;
        $this->resumeAfter = $resumeAfter;
        $this->errorCode   = $errorCode;
    }

    /** The HubSpot connection it reads (null: a file import). */
    public function getConnectionId(): ?string
    {
        return $this->connectionId;
    }

    /**
     * The run cursor of a HubSpot import.
     *
     * @return array<string, mixed>|null
     */
    public function getCursor(): ?array
    {
        return $this->cursor;
    }

    /** When a paused HubSpot import may run again (null: not waiting for a time). */
    public function getResumeAfter(): ?DateTimeImmutable
    {
        return $this->resumeAfter;
    }

    /** Why a HubSpot import paused or failed (`hubspot_rate_limited`, `hubspot_token_invalid`, …), or null. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The state a claim gives (the store writes it with one conditional UPDATE; this mirrors it in memory).
     *
     * @param array<string, string> $mapping
     */
    public function claim(string $token, array $mapping, DuplicatePolicy $policy, DateTimeImmutable $now): void
    {
        $this->status          = ImportStatus::Running;
        $this->claimToken      = $token;
        $this->claimedAt       = $now;
        $this->mapping         = $mapping;
        $this->duplicatePolicy = $policy;
    }

    /**
     * The final state (the store writes it with one conditional UPDATE; this mirrors it in memory).
     */
    public function finish(ImportStatus $status, DateTimeImmutable $now): void
    {
        $this->status     = $status;
        $this->claimToken = null;
        $this->finishedAt = $now;
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

    /** What it imports. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** CSV or vCard. */
    public function getFormat(): ImportFormat
    {
        return $this->format;
    }

    /** The uploaded file's name. */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * The file's columns.
     *
     * @return list<string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Column => field.
     *
     * @return array<string, string>
     */
    public function getMapping(): array
    {
        return $this->mapping;
    }

    /** The state. */
    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    /** What happens to existing e-mails. */
    public function getDuplicatePolicy(): DuplicatePolicy
    {
        return $this->duplicatePolicy;
    }

    /** Rows in the file. */
    public function getRowsTotal(): int
    {
        return $this->rowsTotal;
    }

    /** Rows settled. */
    public function getRowsDone(): int
    {
        return $this->rowsDone;
    }

    /**
     * Counts per outcome.
     *
     * @return array{created: int, updated: int, duplicate: int, invalid: int, limit: int}
     */
    public function counts(): array
    {
        return ['created' => $this->createdCount, 'updated' => $this->updatedCount, 'duplicate' => $this->duplicateCount, 'invalid' => $this->invalidCount, 'limit' => $this->limitCount];
    }

    /**
     * Whether the import is running without a claim: an unattended run stopped before a row that needs a person's
     * decision and released the import for the next person's "Continue".
     */
    public function isAwaitingPerson(): bool
    {
        return $this->status === ImportStatus::Running && $this->claimToken === null;
    }

    /** The claim of the running worker. */
    public function getClaimToken(): ?string
    {
        return $this->claimToken;
    }

    /** When the claim last moved. */
    public function getClaimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    /** When it finished. */
    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** Who uploaded it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    /** When. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }
}
