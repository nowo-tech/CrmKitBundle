<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\RecordService;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\PartyStage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\PartyStageView;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function array_map;
use function max;

/**
 * Lifecycle stages of companies and contacts in party funnels (0.3.0; the pages only). One unit per change: the record
 * locked `FOR UPDATE`, the row unique per funnel and record, the stage change written with the seconds spent in the
 * stage left. Stateless.
 */
final readonly class PartyStageService
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private PipelineSupport $pipeline)
    {
    }

    /**
     * A copy bound to `$caller` for one call; never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->pipeline->as($caller));
    }

    /**
     * Puts a company or contact in a stage of a party funnel of its type; whether it moved (the same stage: false).
     *
     * @throws AccessDeniedException|NotAvailableException `edit` on the record
     * @throws ConflictException `crm_stage_invalid` (another funnel's or kind's stage, archived), merged / trashed record
     */
    public function set(SubjectType $type, string $id, string $funnelId, string $stageId): bool
    {
        $context = $this->pipeline->support->access->base();
        $write   = function (Effects $effects) use ($context, $type, $id, $funnelId, $stageId): bool { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->pipeline->support->load($context, $type, $id, CrmPermission::Edit, lock: true);
            $funnel = RecordId::isValid($funnelId) ? $this->pipeline->store->funnel($context->tenant, $funnelId) : null;
            $stage  = RecordId::isValid($stageId) ? $this->pipeline->store->stage($context->tenant, $stageId) : null;
            if (!$funnel instanceof Funnel || $funnel->getKind() !== FunnelKind::Party || $funnel->getSubjectType() !== $type || $funnel->isArchived()
                || !$stage instanceof Stage || $stage->getFunnelId() !== $funnelId || $stage->isArchived()) {
                throw new ConflictException(ConflictException::STAGE_INVALID);
            }
            $row  = $this->pipeline->store->partyStage($context->tenant, $funnelId, $id);
            $from = $row?->getStageId();
            if ($from === $stageId) {
                return false;
            }
            $seconds = $row instanceof PartyStage ? max(0, $context->now->getTimestamp() - $row->getEnteredAt()->getTimestamp()) : null;
            if ($row instanceof PartyStage) {
                $row->moveTo($stageId, $context->now);
            } else {
                $this->pipeline->support->store->add(new PartyStage($this->pipeline->support->id(), $context->tenant->value, $funnelId, $type, $id, $stageId, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            $this->pipeline->support->store->add(new StageChange($this->pipeline->support->id(), $context->tenant->value, $type, $id, $funnelId, $from, $stageId, $context->actor->value, $context->origin?->channel, $context->now, $seconds)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->pipeline->support->activity($context, $type, $id, ActivityKind::LifecycleStage, ['funnel' => $funnelId, 'stage' => $stageId]);
            $this->pipeline->support->audit($effects, $context, 'crm.lifecycle.changed', $type, $id, ['funnel' => $funnelId, 'stage' => $stageId]);

            return true;
        };

        try {
            return $this->pipeline->support->write($write);
        } catch (DuplicateRowException) {
            // Another writer put the record in the funnel at the same moment: once more, as a move of that row.
            return RecordService::onceMore(fn (): bool => $this->pipeline->support->write($write));
        }
    }

    /**
     * The lifecycle stages of a record the actor may view.
     *
     * @throws AccessDeniedException|NotAvailableException
     *
     * @return list<PartyStageView>
     */
    public function stages(SubjectType $type, string $id): array
    {
        $context = $this->pipeline->support->access->base();

        return $this->pipeline->support->read(function () use ($context, $type, $id): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->pipeline->support->load($context, $type, $id, CrmPermission::View, true);

            return array_map(static fn (PartyStage $p): PartyStageView => new PartyStageView($p->getFunnelId(), $p->getStageId(), $p->getEnteredAt(), $p->getVersion()), $this->pipeline->store->partyStages($context->tenant, $type, $id));
        });
    }
}
