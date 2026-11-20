<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Value\LostReasonKey;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

/**
 * A stage move (0.3.0, `crm_deal_move_stage`): a stage of the deal's funnel; a lost stage needs a lost reason (a key
 * of `pipeline.lost_reasons`) and may carry a note (≤ 500, untrusted).
 */
final readonly class CrmApiStageMove implements CrmApiInput
{
    /**
     * Wires the move.
     */
    public function __construct(public RecordId $stageId, public ?LostReasonKey $lostReason = null, public ?string $lostNote = null)
    {
    }

    /**
     * From an input array (`stage_id`, `lost_reason`, `lost_note`).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        CrmApiFields::only($data, ['stage_id', 'lost_reason', 'lost_note']);
        $reason = CrmApiFields::text($data, 'lost_reason', 32);
        if ($reason !== null && preg_match(LostReasonKey::PATTERN, $reason) !== 1) {
            throw CrmApiRefusal::malformed('lost_reason');
        }

        return new self(CrmApiFields::requiredId($data, 'stage_id'), $reason === null ? null : new LostReasonKey($reason), CrmApiFields::text($data, 'lost_note', 500));
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['stage_id' => $this->stageId->value, 'lost_reason' => $this->lostReason?->value, 'lost_note' => $this->lostNote];
    }
}
