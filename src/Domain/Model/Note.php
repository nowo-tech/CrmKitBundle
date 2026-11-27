<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A plain-text note on a record (shown escaped).
 */
class Note
{
    private int $version = 1;

    private DateTimeImmutable $updatedAt;

    /**
     * @param string $body 1–10 000 characters
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private string $subjectId,
        private string $body,
        private bool $pinned,
        private ?string $authorId,
        private DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
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

    /** The kind of record. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The record. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** The text. */
    public function getBody(): string
    {
        return $this->body;
    }

    /** Whether it is pinned. */
    public function isPinned(): bool
    {
        return $this->pinned;
    }

    /** The author. */
    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    /** Changes the text and the pin. */
    public function edit(string $body, bool $pinned, DateTimeImmutable $now): void
    {
        $this->body      = $body;
        $this->pinned    = $pinned;
        $this->updatedAt = $now;
    }

    /** Pins or unpins it (0.11.0). */
    public function pin(bool $pinned, DateTimeImmutable $now): void
    {
        $this->pinned    = $pinned;
        $this->updatedAt = $now;
    }

    /** Points it at another record (merge). */
    public function moveTo(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** When it was written. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it last changed. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
