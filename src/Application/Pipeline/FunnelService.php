<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\RecordValidator;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function count;
use function trim;

/**
 * Funnel definitions (0.3.0, `manage`; the pages only, never the API): funnels and their stages. Every stage edit
 * locks the funnel row first, so two editors of one funnel run one after the other and positions stay a sequence.
 * A stage's kind is fixed once created (deals in it keep their status). Stateless.
 */
final readonly class FunnelService
{
    /** Most stages per funnel. */
    public const MAX_STAGES = 30;

    /** Most funnels per tenant. */
    public const MAX_FUNNELS = 50;

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
     * Creates a funnel: a deal funnel gets the stages open (10 %), won and lost; a party funnel of companies or
     * contacts gets one open stage. `$names` gives the stage names in the tenant's language (`open`, `won`, `lost`,
     * `first`). The new funnel's id.
     *
     * @param array<string, string> $names
     *
     * @throws AccessDeniedException|InvalidInputException|NotAvailableException
     */
    public function create(FunnelKind $kind, ?SubjectType $partyType, string $name, array $names = []): string
    {
        $names += ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'first' => 'New'];
        $stages = $kind === FunnelKind::Deal ? [[$names['open'], StageKind::Open, 10], [$names['won'], StageKind::Won, 100], [$names['lost'], StageKind::Lost, 0]] : [[$names['first'], StageKind::Open, 0]];
        foreach ($stages as $i => [$stageName]) {
            $stages[$i][0] = $this->name($stageName, 80);
        }
        $context = $this->pipeline->support->access->context(CrmPermission::Manage);
        $name    = $this->name($name, 120);
        if (($kind === FunnelKind::Party) !== ($partyType instanceof SubjectType) || $partyType === SubjectType::Deal) {
            throw InvalidInputException::field('subject_type', 'error.invalid');
        }

        return $this->pipeline->support->write(function (Effects $effects) use ($context, $kind, $partyType, $name, $stages): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $existing = $this->pipeline->store->funnels($context->tenant);
            if (count($existing) >= self::MAX_FUNNELS) {
                throw InvalidInputException::field('name', 'error.funnel_limit');
            }
            $id     = $this->pipeline->support->id();
            $store  = $this->pipeline->support->store;
            $funnel = new Funnel($id, $context->tenant->value, $kind, $partyType, $name, count($existing), $context->now);
            $store->add($funnel); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            foreach ($stages as $position => [$stageName, $stageKind, $probability]) {
                $store->add(new Stage($this->pipeline->support->id(), $context->tenant->value, $id, $stageName, $position, $stageKind, $probability)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            $this->pipeline->support->audit($effects, $context, 'crm.funnel.created', null, $id, ['kind' => $kind->value]);

            return $id;
        });
    }

    /**
     * Renames or archives a funnel (an archived funnel takes no new deals; its deals stay).
     *
     * @throws AccessDeniedException|ConflictException|InvalidInputException|NotAvailableException
     */
    public function change(string $id, string $name, bool $archived, int $expectedVersion): void
    {
        $context = $this->pipeline->support->access->context(CrmPermission::Manage);
        $name    = $this->name($name, 120);
        $this->pipeline->support->write(function (Effects $effects) use ($context, $id, $name, $archived, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnel = $this->pipeline->funnel($context, $id, true);
            if ($funnel->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::SETTINGS_STALE, $id);
            }
            $funnel->change($name, $funnel->getPosition(), $archived, $context->now);
            $this->pipeline->support->audit($effects, $context, 'crm.funnel.changed', null, $id, ['archived' => $archived]);
        }, ConflictException::SETTINGS_STALE);
    }

    /**
     * Adds a stage at the end of the funnel. The new stage's id.
     *
     * @throws AccessDeniedException|InvalidInputException|NotAvailableException
     */
    public function addStage(string $funnelId, string $name, StageKind $kind, int $probability, ?int $rotDays): string
    {
        $context = $this->pipeline->support->access->context(CrmPermission::Manage);
        $name    = $this->name($name, 80);
        $this->numbers($probability, $rotDays);

        return $this->pipeline->support->write(function (Effects $effects) use ($context, $funnelId, $name, $kind, $probability, $rotDays): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnel = $this->pipeline->funnel($context, $funnelId, true);
            $stages = $this->pipeline->stages($context, $funnelId);
            if (count($stages) >= self::MAX_STAGES || ($funnel->getKind() === FunnelKind::Party && $kind !== StageKind::Open)) {
                throw InvalidInputException::field('kind', count($stages) >= self::MAX_STAGES ? 'error.stage_limit' : 'error.invalid');
            }
            $id = $this->pipeline->support->id();
            $this->pipeline->support->store->add(new Stage($id, $context->tenant->value, $funnelId, $name, count($stages), $kind, $probability, $rotDays)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $funnel->change($funnel->getName(), $funnel->getPosition(), $funnel->isArchived(), $context->now);
            $this->pipeline->support->audit($effects, $context, 'crm.funnel.stage_added', null, $funnelId, ['stage' => $id, 'kind' => $kind->value]);

            return $id;
        });
    }

    /**
     * Changes a stage: name, probability, age limit, archived (an archived stage takes no moves; deals in it stay).
     *
     * @throws AccessDeniedException|ConflictException|InvalidInputException|NotAvailableException
     */
    public function changeStage(string $stageId, string $name, int $probability, ?int $rotDays, bool $archived, int $expectedVersion): void
    {
        $context = $this->pipeline->support->access->context(CrmPermission::Manage);
        $name    = $this->name($name, 80);
        $this->numbers($probability, $rotDays);
        $this->pipeline->support->write(function (Effects $effects) use ($context, $stageId, $name, $probability, $rotDays, $archived, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $stage = $this->stage($context, $stageId);
            $this->pipeline->funnel($context, $stage->getFunnelId(), true);
            if ($stage->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::SETTINGS_STALE, $stageId);
            }
            $stage->change($name, $stage->getPosition(), $stage->getKind(), $probability, $rotDays, $archived);
            $this->pipeline->support->audit($effects, $context, 'crm.funnel.stage_changed', null, $stage->getFunnelId(), ['stage' => $stageId, 'archived' => $archived]);
        }, ConflictException::SETTINGS_STALE);
    }

    /**
     * Moves a stage one place up (`-1`) or down (`1`); positions stay a sequence.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function moveStage(string $stageId, int $direction): void
    {
        $context = $this->pipeline->support->access->context(CrmPermission::Manage);
        $this->pipeline->support->write(function (Effects $effects) use ($context, $stageId, $direction): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $stage = $this->stage($context, $stageId);
            $this->pipeline->funnel($context, $stage->getFunnelId(), true);
            $stages = $this->pipeline->stages($context, $stage->getFunnelId());
            foreach ($stages as $i => $candidate) {
                if ($candidate->getId() !== $stageId) {
                    continue;
                }
                $other = $stages[$i + ($direction < 0 ? -1 : 1)] ?? null;
                if (!$other instanceof Stage) {
                    return;
                }
                [$a, $b] = [$candidate->getPosition(), $other->getPosition()];
                $candidate->moveTo($b === $a ? $a + ($direction < 0 ? -1 : 1) : $b);
                $other->moveTo($a);
            }
            $this->pipeline->support->audit($effects, $context, 'crm.funnel.stage_moved', null, $stage->getFunnelId(), ['stage' => $stageId]);
        });
    }

    private function stage(CrmContext $context, string $id): Stage
    {
        $stage = RecordId::isValid($id) ? $this->pipeline->store->stage($context->tenant, $id) : null;

        return $stage instanceof Stage ? $stage : throw new NotAvailableException();
    }

    /**
     * A trimmed name of 1–`$max` characters (untrusted text).
     *
     * @throws InvalidInputException
     */
    private function name(string $name, int $max): string
    {
        $name  = trim($name);
        $error = $name === '' ? 'error.required' : RecordValidator::check($name, $max, 'text');
        if ($error !== null) {
            throw InvalidInputException::field('name', $error);
        }

        return $name;
    }

    /**
     * @throws InvalidInputException
     */
    private function numbers(int $probability, ?int $rotDays): void
    {
        if ($probability < 0 || $probability > 100) {
            throw InvalidInputException::field('probability', 'error.invalid');
        }
        if ($rotDays !== null && ($rotDays < 1 || $rotDays > 3650)) {
            throw InvalidInputException::field('rot_days', 'error.invalid');
        }
    }
}
