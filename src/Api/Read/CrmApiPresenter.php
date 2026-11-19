<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Read;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelStats;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\NextActionView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\PartyStageView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageChangeView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageStats;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;

use function array_map;
use function array_slice;
use function count;

/**
 * Builds the API's read models from the CRM's views (0.3.0, design §6.4): facts apart from untrusted text
 * ({@see CrmApiItem}). Amounts only when the caller passes them in. Stateless.
 *
 * @internal
 */
final class CrmApiPresenter
{
    /** Most affiliations, tags and next actions in one record read. */
    public const MAX_AFFILIATIONS = 50;

    public const MAX_TAGS = 50;

    public const MAX_NEXT_ACTIONS = 10;

    private function __construct()
    {
    }

    /**
     * A company or contact.
     *
     * @param list<PartyStageView> $lifecycle
     * @param list<NextActionView> $nextActions
     * @param list<array{currency: string, open: string}>|null $amounts open deal values per currency (null: hidden or
     *                                                                  no funnels)
     */
    public static function record(RecordView $view, array $lifecycle = [], array $nextActions = [], ?array $amounts = null, bool $amountsHidden = false): CrmApiItem
    {
        $tags         = array_slice($view->tags, 0, self::MAX_TAGS);
        $affiliations = array_slice($view->affiliations, 0, self::MAX_AFFILIATIONS);
        $tagNames     = [];
        foreach ($tags as $tag) {
            $tagNames[$tag->id] = $tag->name;
        }

        return new CrmApiItem('record', [
            'type'                   => $view->type->value,
            'id'                     => $view->id,
            'status'                 => $view->status->value,
            'version'                => $view->version,
            'merged_into_id'         => null,
            'created_at'             => CrmApiTime::format($view->createdAt),
            'updated_at'             => CrmApiTime::format($view->updatedAt),
            'owner_id'               => $view->ownerId,
            'roles'                  => $view->roles,
            'tag_ids'                => array_map(static fn (TagView $t): string => $t->id, $tags),
            'tags_truncated'         => count($view->tags) > self::MAX_TAGS,
            'affiliations'           => array_map(static fn (AffiliationView $a): CrmApiItem => self::affiliation($a, $view->type), $affiliations),
            'affiliations_truncated' => count($view->affiliations) > self::MAX_AFFILIATIONS,
            'lifecycle'              => array_map(static fn (PartyStageView $s): array => ['funnel_id' => $s->funnelId, 'stage_id' => $s->stageId, 'entered_at' => CrmApiTime::format($s->enteredAt)], $lifecycle),
            'next_actions'           => array_map(self::nextAction(...), array_slice($nextActions, 0, self::MAX_NEXT_ACTIONS)),
            'amounts'                => $amounts,
            'amounts_hidden'         => $amountsHidden,
        ], [
            'label'  => $view->label,
            'values' => $view->values,
            'custom' => $view->custom,
            'tags'   => $tagNames,
            'owner'  => $view->ownerName,
        ]);
    }

    /**
     * A merged record: only where it went, when the survivor is visible.
     */
    public static function tombstone(SubjectType $type, string $id, int $version, ?string $mergedIntoId): CrmApiItem
    {
        return new CrmApiItem('record', ['type' => $type->value, 'id' => $id, 'status' => 'merged', 'version' => $version, 'merged_into_id' => $mergedIntoId]);
    }

    /**
     * An affiliation seen from `$side`: the other side's id and label.
     */
    public static function affiliation(AffiliationView $view, SubjectType $side): CrmApiItem
    {
        $company = $side === SubjectType::Contact;

        return new CrmApiItem('affiliation', [
            'id'              => $view->id,
            'contact_id'      => $view->contactId,
            'company_id'      => $view->companyId,
            'other_id'        => $company ? $view->companyId : $view->contactId,
            'primary'         => $view->primary,
            'primary_contact' => $view->primaryContact,
            'purposes'        => $view->purposes,
        ], ['role' => $view->role, 'label' => $company ? $view->companyName : $view->contactName]);
    }

    /**
     * A search hit.
     */
    public static function hit(RecordView $view): CrmApiItem
    {
        return new CrmApiItem('hit', ['type' => $view->type->value, 'id' => $view->id, 'status' => $view->status->value, 'owner_id' => $view->ownerId, 'roles' => $view->roles], ['label' => $view->label]);
    }

    /**
     * A timeline entry (`via` / `token` and every other param are text: they may hold names).
     */
    public static function activity(ActivityView $view, ?string $noteBody): CrmApiItem
    {
        return new CrmApiItem('activity', [
            'id'           => $view->id,
            'subject_type' => $view->subjectType->value,
            'subject_id'   => $view->subjectId,
            'kind'         => $view->kind->value,
            'occurred_at'  => CrmApiTime::format($view->occurredAt),
        ], ['subject_label' => $view->subjectLabel, 'actor' => $view->actorName, 'params' => $view->params, 'note' => $noteBody]);
    }

    /**
     * A note.
     */
    public static function note(NoteView $view): CrmApiItem
    {
        return new CrmApiItem('note', [
            'id'         => $view->id,
            'pinned'     => $view->pinned,
            'author_id'  => $view->authorId,
            'version'    => $view->version,
            'created_at' => CrmApiTime::format($view->createdAt),
            'updated_at' => CrmApiTime::format($view->updatedAt),
        ], ['body' => $view->body, 'author' => $view->authorName]);
    }

    /**
     * The consents of a contact: the current state per purpose (configured ones, then stored ones no longer
     * configured, flagged), with the log when asked.
     *
     * @param list<ConsentView> $rows newest first
     * @param list<string> $purposes configured purposes
     */
    public static function consents(string $contactId, array $rows, array $purposes, bool $history): CrmApiItem
    {
        $current = [];
        foreach ($rows as $row) {
            $current[$row->purpose] ??= $row;
        }
        $states = [];
        foreach ($purposes as $purpose) {
            $row      = $current[$purpose] ?? null;
            $states[] = self::consentState($purpose, $row, true);
            unset($current[$purpose]);
        }
        foreach ($current as $purpose => $row) {
            $states[] = self::consentState((string) $purpose, $row, false);
        }
        $log = $history ? array_map(static fn (ConsentView $c): array => ['id' => $c->id, 'purpose' => $c->purpose, 'granted' => $c->granted, 'source' => $c->source->value, 'recorded_at' => CrmApiTime::format($c->recordedAt)], array_slice($rows, 0, 200)) : null;

        return new CrmApiItem('consents', ['contact_id' => $contactId, 'purposes' => $states, 'history' => $log], [
            'recorded_by' => array_map(static fn (ConsentView $c): ?string => $c->recordedBy, array_slice($rows, 0, $history ? 200 : 0)),
            'evidence'    => array_map(static fn (ConsentView $c): ?string => $c->evidence, array_slice($rows, 0, $history ? 200 : 0)),
        ]);
    }

    /**
     * A tag.
     */
    public static function tag(TagView $view): CrmApiItem
    {
        return new CrmApiItem('tag', ['id' => $view->id, 'colour' => $view->colour, 'version' => $view->version], ['name' => $view->name]);
    }

    /**
     * A host link (resolved by the host).
     */
    public static function link(LinkView $view): CrmApiItem
    {
        return new CrmApiItem('link', ['id' => $view->id, 'host_type' => $view->hostType, 'host_id' => $view->hostId, 'url' => $view->url], ['label' => $view->label]);
    }

    /**
     * A next action.
     */
    public static function nextAction(NextActionView $view): CrmApiItem
    {
        return new CrmApiItem('next_action', [
            'id'           => $view->id,
            'subject_type' => $view->subjectType->value,
            'subject_id'   => $view->subjectId,
            'due_at'       => CrmApiTime::format($view->dueAt),
            'assignee_id'  => $view->assigneeId,
            'status'       => $view->status->value,
            'task'         => $view->taskHidden ? ['hidden' => true] : ['type' => $view->taskType, 'id' => $view->taskId],
            'created_at'   => CrmApiTime::format($view->createdAt),
        ], ['title' => $view->title]);
    }

    /**
     * A deal (amounts only when the view carries them).
     */
    public static function deal(DealView $view): CrmApiItem
    {
        return new CrmApiItem('deal', [
            'id'                    => $view->id,
            'funnel_id'             => $view->funnelId,
            'stage_id'              => $view->stageId,
            'stage_kind'            => $view->stageKind->value,
            'status'                => $view->status->value,
            'version'               => $view->version,
            'company'               => $view->companyHidden ? ['id' => null, 'hidden' => true] : ['id' => $view->companyId, 'hidden' => false],
            'contact'               => $view->contactHidden ? ['id' => null, 'hidden' => true] : ['id' => $view->contactId, 'hidden' => false],
            'owner_id'              => $view->ownerId,
            'amount'                => $view->amount === null ? null : ['amount' => $view->amount->amount, 'currency' => $view->amount->currency],
            'probability'           => $view->amountsHidden ? null : $view->probability,
            'effective_probability' => $view->amountsHidden ? null : $view->effectiveProbability,
            'weighted_value'        => $view->weightedValue,
            'amounts_hidden'        => $view->amountsHidden,
            'expected_close'        => $view->expectedClose?->value,
            'stage_entered_at'      => CrmApiTime::format($view->stageEnteredAt),
            'closed_at'             => CrmApiTime::format($view->closedAt),
            'next_action_at'        => CrmApiTime::format($view->nextActionAt),
            'stale'                 => $view->stale,
            'lost_reason'           => $view->lostReason,
            'created_at'            => CrmApiTime::format($view->createdAt),
            'updated_at'            => CrmApiTime::format($view->updatedAt),
            'history'               => array_map(static fn (StageChangeView $c): array => ['from_stage_id' => $c->fromStageId, 'to_stage_id' => $c->toStageId, 'actor_id' => $c->actorId, 'via' => $c->via, 'at' => CrmApiTime::format($c->occurredAt), 'seconds_in_from' => $c->secondsInFrom], $view->history),
            'next_actions'          => array_map(self::nextAction(...), $view->nextActions),
        ], [
            'title'         => $view->title,
            'lost_note'     => $view->lostNote,
            'company_label' => $view->companyHidden ? null : $view->companyLabel,
            'contact_label' => $view->contactHidden ? null : $view->contactLabel,
            'owner'         => $view->ownerName,
        ]);
    }

    /**
     * A funnel with its stages.
     */
    public static function funnel(FunnelView $view): CrmApiItem
    {
        return new CrmApiItem('funnel', [
            'id'           => $view->id,
            'kind'         => $view->kind->value,
            'subject_type' => $view->subjectType?->value,
            'position'     => $view->position,
            'archived'     => $view->archived,
            'version'      => $view->version,
            'stages'       => array_map(static fn (StageView $s): array => ['id' => $s->id, 'position' => $s->position, 'kind' => $s->kind->value, 'probability' => $s->probability, 'rot_days' => $s->rotDays, 'archived' => $s->archived], $view->stages),
        ], [
            'name'   => $view->name,
            'stages' => array_map(static fn (StageView $s): array => ['id' => $s->id, 'name' => $s->name], $view->stages),
        ]);
    }

    /**
     * The statistics of a funnel (values only when the view carries them).
     */
    public static function stats(FunnelStats $stats): CrmApiItem
    {
        return new CrmApiItem('funnel_stats', [
            'funnel_id'     => $stats->funnelId,
            'at'            => CrmApiTime::format($stats->at),
            'past'          => $stats->past,
            'values_hidden' => $stats->valuesHidden,
            'stages'        => array_map(static fn (StageStats $s): array => ['id' => $s->stageId, 'kind' => $s->kind->value, 'position' => $s->position, 'count' => $s->count, 'stale' => $s->stale, 'values' => $stats->valuesHidden ? null : $s->values], $stats->stages),
        ], [
            'stages' => array_map(static fn (StageStats $s): array => ['id' => $s->stageId, 'name' => $s->name], $stats->stages),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function consentState(string $purpose, ?ConsentView $row, bool $configured): array
    {
        return [
            'purpose'     => $purpose,
            'configured'  => $configured,
            'granted'     => $row?->granted,
            'source'      => $row?->source->value,
            'recorded_at' => CrmApiTime::format($row?->recordedAt),
        ];
    }
}
