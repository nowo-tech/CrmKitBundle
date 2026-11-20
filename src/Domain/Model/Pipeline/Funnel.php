<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A funnel of a tenant (0.3.0): a deal funnel, or a party lifecycle funnel of companies or contacts, with ordered
 * stages ({@see Stage}). The name is tenant-written text (untrusted).
 */
class Funnel
{
    private bool $archived = false;

    private int $version = 1;

    private DateTimeImmutable $updatedAt;

    /**
     * @param SubjectType|null $subjectType party funnels: company or contact; null for a deal funnel
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private FunnelKind $kind,
        private ?SubjectType $subjectType,
        private string $name,
        private int $position,
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

    /** Deal or party. */
    public function getKind(): FunnelKind
    {
        return $this->kind;
    }

    /** The kind of party of a lifecycle funnel (null for deals). */
    public function getSubjectType(): ?SubjectType
    {
        return $this->subjectType;
    }

    /** The name (untrusted). */
    public function getName(): string
    {
        return $this->name;
    }

    /** The position among the tenant's funnels. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Whether it is archived (no new items, still readable). */
    public function isArchived(): bool
    {
        return $this->archived;
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

    /** When it last changed. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Renames, moves or archives it. */
    public function change(string $name, int $position, bool $archived, DateTimeImmutable $now): void
    {
        $this->name      = $name;
        $this->position  = $position;
        $this->archived  = $archived;
        $this->updatedAt = $now;
    }
}
