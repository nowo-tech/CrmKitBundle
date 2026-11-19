<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

/**
 * A new deal (0.3.0, `crm_deal_create`): title (1–200, untrusted), funnel and stage (an open stage of a deal funnel),
 * a company and / or a contact the principal sees, owner (default `me`), amount and probability (need the amounts
 * tick), expected close.
 */
final readonly class CrmApiDealDraft implements CrmApiInput
{
    /** The owner. */
    public CrmApiOwner $owner;

    /**
     * Wires the draft.
     */
    public function __construct(
        public string $title,
        public RecordId $funnelId,
        public RecordId $stageId,
        public ?RecordId $companyId = null,
        public ?RecordId $contactId = null,
        ?CrmApiOwner $owner = null,
        public ?Money $amount = null,
        public ?int $probability = null,
        public ?DateImmutable $expectedClose = null,
    ) {
        $this->owner = $owner ?? CrmApiOwner::me();
    }

    /**
     * From an input array (`title`, `funnel_id`, `stage_id`, `company_id`, `contact_id`, `owner`, `amount`,
     * `currency`, `probability`, `expected_close`).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        CrmApiFields::only($data, ['title', 'funnel_id', 'stage_id', 'company_id', 'contact_id', 'owner', 'amount', 'currency', 'probability', 'expected_close']);

        return new self(
            CrmApiFields::requiredText($data, 'title', 400),
            CrmApiFields::requiredId($data, 'funnel_id'),
            CrmApiFields::requiredId($data, 'stage_id'),
            CrmApiFields::id($data, 'company_id'),
            CrmApiFields::id($data, 'contact_id'),
            CrmApiFields::has($data, 'owner') ? CrmApiOwner::parse($data['owner']) : null,
            CrmApiDealFields::money($data),
            CrmApiFields::int($data, 'probability', 0, 100),
            CrmApiDealFields::date($data, 'expected_close'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return [
            'title'          => $this->title,
            'funnel_id'      => $this->funnelId->value,
            'stage_id'       => $this->stageId->value,
            'company_id'     => $this->companyId?->value,
            'contact_id'     => $this->contactId?->value,
            'owner'          => $this->owner->canonical(),
            'amount'         => $this->amount?->amount,
            'currency'       => $this->amount?->currency,
            'probability'    => $this->probability,
            'expected_close' => $this->expectedClose?->value,
        ];
    }
}
