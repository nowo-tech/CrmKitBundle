<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function array_map;
use function in_array;
use function mb_strlen;
use function preg_match;

/**
 * A deal search (0.3.0, `crm_deal_search`): funnel, stages, status (open / won / lost), owner, company, contact,
 * text (≤ 100, untrusted), stale only; order `updated` | `expected_close` | `amount` (amount needs the amounts tick).
 */
final readonly class CrmApiDealQuery
{
    public const SORTS = ['updated', 'expected_close', 'amount'];

    /**
     * @param list<RecordId>|null $stageIds
     */
    public function __construct(
        public ?RecordId $funnelId = null,
        public ?array $stageIds = null,
        public ?DealStatus $status = null,
        public ?ActorRef $ownerId = null,
        public ?RecordId $companyId = null,
        public ?RecordId $contactId = null,
        public ?string $text = null,
        public bool $staleOnly = false,
        public string $sort = 'updated',
        public CrmApiPageRequest $page = new CrmApiPageRequest(),
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw CrmApiRefusal::malformed('sort');
        }
        if ($status === DealStatus::Trashed) {
            throw CrmApiRefusal::malformed('status');
        }
        if ($text !== null && (preg_match('//u', $text) !== 1 || mb_strlen($text) > 100)) {
            throw CrmApiRefusal::malformed('q');
        }
    }

    /**
     * The filters, without the page (the cursor's filter hash).
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'funnel'  => $this->funnelId?->value,
            'stages'  => $this->stageIds === null ? null : array_map(static fn (RecordId $s): string => $s->value, $this->stageIds),
            'status'  => $this->status?->value,
            'owner'   => $this->ownerId?->value,
            'company' => $this->companyId?->value,
            'contact' => $this->contactId?->value,
            'q'       => $this->text,
            'stale'   => $this->staleOnly,
            'sort'    => $this->sort,
        ];
    }
}
