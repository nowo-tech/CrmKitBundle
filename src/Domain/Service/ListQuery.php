<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

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
     * @param string|null $role only records holding this party role (a key of `roles.catalogue`)
     * @param string|null $purpose contacts only: only contacts with an affiliation of this purpose (a key of
     *                             `affiliations.purposes`)
     * @param bool $primaryContactOnly contacts only: only contacts that are the primary contact of a company (of
     *                                 `$companyId` when set)
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
    ) {
        $this->sort      = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $this->direction = $direction === 'desc' ? 'desc' : 'asc';
        $this->page      = min(self::MAX_PAGE, max(1, $page));
        $this->perPage   = min(100, max(1, $perPage));
    }
}
