<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Value\AffiliationPurpose;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;

use function in_array;
use function max;
use function min;

/**
 * Filters, sort and page of a list (contract section 11.1).
 */
final readonly class ListQuery
{
    /** Sortable columns. */
    public const SORTS = ['name', 'created', 'updated'];

    /** Highest page (a bounded OFFSET: deeper pages are reached with filters). */
    public const MAX_PAGE = 10000;

    public string $sort;

    public string $direction;

    public int $page;

    public int $perPage;

    /**
     * @param string|null $text free text (name, e-mail, tax id, domain)
     * @param RecordStatus $status Active or Trashed
     * @param string|null $role only records holding this party role (a key of `roles.catalogue`; a string that is
     *                          not a valid key is refused with `InvalidInputException`, as `$purpose`)
     * @param string|null $purpose contacts only: only contacts with an affiliation of this purpose (a key of
     *                             `affiliations.purposes`)
     * @param bool $primaryContactOnly contacts only: only contacts that are the primary contact of a company (of
     *                                 `$companyId` when set)
     * @param bool $ownerMe 0.10.2: only the acting person's records (resolved with {@see self::forActor()} by the
     *                      reader before the store sees the query; `$ownerId` is ignored)
     * @param bool $ownerNone 0.10.2: only records without an owner (`$ownerId` is ignored)
     * @param DateTimeImmutable|null $createdFrom 0.10.2: created on or after this day (UTC, whole days)
     * @param DateTimeImmutable|null $createdTo 0.10.2: created on or before this day
     * @param DateTimeImmutable|null $updatedFrom 0.10.2: last changed on or after this day
     * @param DateTimeImmutable|null $updatedTo 0.10.2: last changed on or before this day
     */
    public function __construct(
        public SubjectType $type,
        public ?string $text = null,
        public ?string $tagId = null,
        public ?string $ownerId = null,
        public RecordStatus $status = RecordStatus::Active,
        string $sort = 'name',
        string $direction = 'asc',
        int $page = 1,
        int $perPage = 25,
        public ?string $companyId = null,
        public ?string $role = null,
        public ?string $purpose = null,
        public bool $primaryContactOnly = false,
        public bool $ownerMe = false,
        public bool $ownerNone = false,
        public ?DateTimeImmutable $createdFrom = null,
        public ?DateTimeImmutable $createdTo = null,
        public ?DateTimeImmutable $updatedFrom = null,
        public ?DateTimeImmutable $updatedTo = null,
    ) {
        if ($ownerMe && $ownerNone) {
            throw InvalidInputException::field('owner', 'error.owner_filter');
        }
        if ($role !== null && !RoleKey::isValid($role)) {
            throw InvalidInputException::field('role', 'error.role_unknown');
        }
        if ($purpose !== null && !AffiliationPurpose::isValid($purpose)) {
            throw InvalidInputException::field('purpose', 'error.purpose_unknown');
        }
        $this->sort      = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $this->direction = $direction === 'desc' ? 'desc' : 'asc';
        $this->page      = min(self::MAX_PAGE, max(1, $page));
        $this->perPage   = min(100, max(1, $perPage));
    }

    /**
     * The query for one actor (0.10.2): `ownerMe` becomes that actor's owner id; any other query is returned as is.
     */
    public function forActor(string $actorId): self
    {
        if (!$this->ownerMe) {
            return $this;
        }

        return new self($this->type, $this->text, $this->tagId, $actorId, $this->status, $this->sort, $this->direction, $this->page, $this->perPage, $this->companyId, $this->role, $this->purpose, $this->primaryContactOnly, false, false, $this->createdFrom, $this->createdTo, $this->updatedFrom, $this->updatedTo);
    }
}
