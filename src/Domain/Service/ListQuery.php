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

    public string $sort;

    public string $direction;

    public int $page;

    public int $perPage;

    /**
     * @param string|null $text free text (name, e-mail, tax id, domain)
     * @param RecordStatus $status Active or Trashed
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
    ) {
        $this->sort      = in_array($sort, self::SORTS, true) ? $sort : 'name';
        $this->direction = $direction === 'desc' ? 'desc' : 'asc';
        $this->page      = max(1, $page);
        $this->perPage   = min(100, max(1, $perPage));
    }
}
