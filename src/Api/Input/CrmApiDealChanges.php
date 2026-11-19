<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function in_array;

/**
 * A partial change of a deal (0.3.0, `crm_deal_update`): each of `title`, `company_id`, `contact_id`, `owner`,
 * `amount` (+ `currency`), `probability`, `expected_close` is present or absent; an absent one keeps its value, a
 * present null clears it.
 */
final readonly class CrmApiDealChanges implements CrmApiInput
{
    /**
     * @param list<string> $fields the fields sent (`title`, `company_id`, `contact_id`, `owner`, `amount`,
     *                             `probability`, `expected_close`)
     */
    public function __construct(
        public array $fields,
        public ?string $title = null,
        public ?RecordId $companyId = null,
        public ?RecordId $contactId = null,
        public ?CrmApiOwner $owner = null,
        public ?Money $amount = null,
        public ?int $probability = null,
        public ?DateImmutable $expectedClose = null,
    ) {
    }

    /**
     * From an input array.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        CrmApiFields::only($data, ['title', 'company_id', 'contact_id', 'owner', 'amount', 'currency', 'probability', 'expected_close']);
        $fields = [];
        foreach (['title', 'company_id', 'contact_id', 'owner', 'probability', 'expected_close'] as $field) {
            if (CrmApiFields::has($data, $field)) {
                $fields[] = $field;
            }
        }
        if (CrmApiFields::has($data, 'amount') || CrmApiFields::has($data, 'currency')) {
            $fields[] = 'amount';
        }

        return new self(
            $fields,
            CrmApiFields::text($data, 'title', 400),
            CrmApiFields::id($data, 'company_id'),
            CrmApiFields::id($data, 'contact_id'),
            CrmApiFields::has($data, 'owner') ? CrmApiOwner::parse($data['owner'] ?? 'none') : null,
            CrmApiDealFields::money($data),
            CrmApiFields::int($data, 'probability', 0, 100),
            CrmApiDealFields::date($data, 'expected_close'),
        );
    }

    /**
     * Whether a field was sent.
     */
    public function has(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    /**
     * Whether the change touches amounts (needs the amounts tick).
     */
    public function touchesAmounts(): bool
    {
        return $this->has('amount') || $this->has('probability');
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return [
            'fields'         => $this->fields,
            'title'          => $this->title,
            'company_id'     => $this->companyId?->value,
            'contact_id'     => $this->contactId?->value,
            'owner'          => $this->owner?->canonical(),
            'amount'         => $this->amount?->amount,
            'currency'       => $this->amount?->currency,
            'probability'    => $this->probability,
            'expected_close' => $this->expectedClose?->value,
        ];
    }
}
