<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A company or contact as pages show it (contract section 7).
 */
final readonly class RecordView
{
    /**
     * @param string $label name or display name
     * @param array<string, string|null> $values core fields
     * @param array<string, string> $custom custom values
     * @param list<TagView> $tags
     * @param list<AffiliationView> $affiliations companies of a contact, contacts of a company
     */
    public function __construct(
        public SubjectType $type,
        public string $id,
        public string $label,
        public array $values,
        public array $custom,
        public ?string $ownerId,
        public ?string $ownerName,
        public array $tags,
        public RecordStatus $status,
        public ?string $mergedIntoId,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $trashedAt,
        public array $affiliations,
    ) {
    }
}
