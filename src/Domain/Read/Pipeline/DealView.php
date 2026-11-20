<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;

/**
 * A deal as the pages and the API read it (0.3.0). The amount, the effective probability's weighted value and the
 * amount-based fields are null with `amountsHidden` when the reader may not view amounts. The company and the contact
 * are shown (id and label) only when visible to the reader; else the flags say a hidden one exists. Text (title,
 * labels, lost note) is untrusted.
 */
final readonly class DealView
{
    /**
     * @param list<StageChangeView> $history newest first, at most 50
     * @param list<NextActionView> $nextActions open ones, earliest due first, at most 10
     */
    public function __construct(
        public string $id,
        public string $funnelId,
        public string $stageId,
        public StageKind $stageKind,
        public DealStatus $status,
        public string $title,
        public ?string $companyId,
        public ?string $companyLabel,
        public bool $companyHidden,
        public ?string $contactId,
        public ?string $contactLabel,
        public bool $contactHidden,
        public ?string $ownerId,
        public ?string $ownerName,
        public ?Money $amount,
        public ?int $probability,
        public int $effectiveProbability,
        public ?string $weightedValue,
        public bool $amountsHidden,
        public ?DateImmutable $expectedClose,
        public DateTimeImmutable $stageEnteredAt,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $nextActionAt,
        public bool $stale,
        public ?string $lostReason,
        public ?string $lostNote,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $history = [],
        public array $nextActions = [],
    ) {
    }
}
