<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;

use function in_array;

/**
 * What the pages or the API send to create or change a deal (0.3.0). On a change, only the fields listed in
 * `$fields` apply (a field not listed keeps its value); on a create every field applies. The title is untrusted text
 * (1–200 characters after the trim).
 */
final readonly class DealInput
{
    /** Every field a change may carry. */
    public const FIELDS = ['title', 'company_id', 'contact_id', 'owner', 'amount', 'probability', 'expected_close'];

    /**
     * @param string|null $funnelId create only: the deal funnel
     * @param string|null $stageId create only: an open stage of the funnel (null: its first open stage)
     * @param string|null $ownerId null: nobody
     * @param list<string> $fields the fields that apply on a change
     */
    public function __construct(
        public ?string $title = null,
        public ?string $funnelId = null,
        public ?string $stageId = null,
        public ?string $companyId = null,
        public ?string $contactId = null,
        public ?string $ownerId = null,
        public ?Money $amount = null,
        public ?int $probability = null,
        public ?DateImmutable $expectedClose = null,
        public array $fields = self::FIELDS,
    ) {
    }

    /**
     * Whether a field applies.
     */
    public function has(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }
}
