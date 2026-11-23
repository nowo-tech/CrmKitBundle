<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * One parsed row of an import (unique per import and row number); settled once by `UPDATE … WHERE outcome = 'pending'`.
 * **(0.10.0)** A HubSpot row is stored already settled and holds only `{"object": …, "id": …}`: no property value.
 */
class ImportRow
{
    private RowOutcome $outcome = RowOutcome::Pending;

    private ?string $recordId = null;

    private ?string $message = null;

    /**
     * @param array<string, string> $data column => cell
     */
    public function __construct(
        private string $id,
        private string $importId,
        private string $tenantId,
        private int $rowNo,
        private array $data,
        private ?SubjectType $subjectType = null,
    ) {
    }

    /** 0.10.0: the record type of the row when it differs per row (HubSpot); null = the import's type. */
    public function getSubjectType(): ?SubjectType
    {
        return $this->subjectType;
    }

    /**
     * Settles the row (the store writes it with one conditional UPDATE; this mirrors it in memory).
     */
    public function settle(RowOutcome $outcome, ?string $recordId, ?string $message): void
    {
        $this->outcome  = $outcome;
        $this->recordId = $recordId;
        $this->message  = $message;
    }

    /** The row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The import. */
    public function getImportId(): string
    {
        return $this->importId;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** 1-based row number in the file (header excluded). */
    public function getRowNo(): int
    {
        return $this->rowNo;
    }

    /**
     * Column => cell.
     *
     * @return array<string, string>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /** What happened to it. */
    public function getOutcome(): RowOutcome
    {
        return $this->outcome;
    }

    /** The record it created or updated. */
    public function getRecordId(): ?string
    {
        return $this->recordId;
    }

    /** The error key of an invalid row. */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
