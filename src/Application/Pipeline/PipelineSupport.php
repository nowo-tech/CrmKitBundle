<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageView;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_filter;
use function array_map;
use function array_values;

/**
 * What the funnel services share (0.3.0): the CRM's write support, the funnel store, the configured lost reasons, and
 * the loading rules of deals and funnels: a missing deal, one of another tenant or one in the trash ⇒ 404; then the
 * permission on the deal (owner-based visibility and `nowo_crm_kit.record_access_check` with subject type `deal`).
 * Stateless.
 *
 * @internal
 */
final readonly class PipelineSupport
{
    /**
     * @param list<string> $lostReasons keys of `pipeline.lost_reasons`
     */
    public function __construct(
        public WriteSupport $support,
        public PipelineStoreInterface $store,
        public array $lostReasons = ['price', 'timing', 'competitor', 'no_decision', 'other'],
    ) {
    }

    /**
     * A copy bound to `$caller` for one call.
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->support->as($caller), $this->store, $this->lostReasons);
    }

    /**
     * Loads a deal of the tenant and checks the permission on it (a refused `view` is a 404).
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function deal(CrmContext $context, string $id, CrmPermission $permission, bool $lock = false, bool $trashed = false): Deal
    {
        $deal = RecordId::isValid($id) ? $this->store->deal($context->tenant, $id, $lock) : null;
        if (!$deal instanceof Deal || (!$trashed && $deal->isTrashed())) {
            throw new NotAvailableException();
        }
        // Someone who may not even see the deal learns nothing more than "not found".
        $this->require($context, CrmPermission::View, $deal, true);
        if ($permission !== CrmPermission::View) {
            $this->require($context, $permission, $deal);
        }

        return $deal;
    }

    /**
     * Requires a permission on a deal (a refused view is a 404 when `$hide`).
     */
    public function require(CrmContext $context, CrmPermission $permission, Deal $deal, bool $hide = false): void
    {
        try {
            $this->support->access->require($context, $permission, SubjectRef::of(SubjectType::Deal, $deal->getId(), $deal->getOwnerId()));
        } catch (AccessDeniedException $e) {
            throw $hide ? new NotAvailableException() : $e;
        }
    }

    /**
     * An active company or contact of the tenant the actor may view, as the relation of a deal; anything else (missing,
     * hidden, merged, trashed) is the same input error, so a hidden record cannot be told from a missing one.
     *
     * @throws InvalidInputException
     */
    public function related(CrmContext $context, SubjectType $type, string $id): CrmRecord
    {
        $field = $type === SubjectType::Company ? 'company_id' : 'contact_id';
        try {
            return $this->support->load($context, $type, $id, CrmPermission::View);
        } catch (NotAvailableException|AccessDeniedException|ConflictException) {
            throw InvalidInputException::field($field, 'error.deal_related_unknown');
        }
    }

    /**
     * A funnel of the tenant with its stages.
     *
     * @throws NotAvailableException
     */
    public function funnel(CrmContext $context, string $id, bool $lock = false): Funnel
    {
        $funnel = RecordId::isValid($id) ? $this->store->funnel($context->tenant, $id, $lock) : null;

        return $funnel instanceof Funnel ? $funnel : throw new NotAvailableException();
    }

    /**
     * The stages of a funnel, by position.
     *
     * @return list<Stage>
     */
    public function stages(CrmContext $context, string $funnelId): array
    {
        return $this->store->stages($context->tenant, [$funnelId]);
    }

    /**
     * The view of a funnel.
     *
     * @param list<Stage> $stages
     */
    public static function view(Funnel $funnel, array $stages): FunnelView
    {
        $own = array_values(array_filter($stages, static fn (Stage $s): bool => $s->getFunnelId() === $funnel->getId()));

        return new FunnelView(
            $funnel->getId(),
            $funnel->getKind(),
            $funnel->getSubjectType(),
            $funnel->getName(),
            $funnel->getPosition(),
            $funnel->isArchived(),
            $funnel->getVersion(),
            array_map(static fn (Stage $s): StageView => new StageView($s->getId(), $s->getFunnelId(), $s->getName(), $s->getPosition(), $s->getKind(), $s->getProbability(), $s->getRotDays(), $s->isArchived(), $s->getVersion()), $own),
        );
    }

    /**
     * Refuses a funnel of the wrong kind (a deal in a party funnel, …) as an input error on the field.
     *
     * @throws InvalidInputException
     */
    public static function kind(Funnel $funnel, FunnelKind $kind, string $field = 'funnel_id'): void
    {
        if ($funnel->getKind() !== $kind) {
            throw InvalidInputException::field($field, 'error.funnel_kind');
        }
    }
}
