<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;

use function array_map;

/**
 * The funnels of the tenant with their stages (0.3.0, `view`). Stateless.
 */
final readonly class FunnelReader
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private PipelineSupport $pipeline)
    {
    }

    /**
     * A copy bound to `$caller` for one call (an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->pipeline->as($caller));
    }

    /**
     * The funnels by position, with their stages (archived ones only when asked).
     *
     * @throws AccessDeniedException|NotAvailableException
     *
     * @return list<FunnelView>
     */
    public function funnels(?FunnelKind $kind = null, bool $withArchived = false): array
    {
        $context = $this->pipeline->support->access->context(CrmPermission::View);

        return $this->pipeline->support->read(function () use ($context, $kind, $withArchived): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnels = $this->pipeline->store->funnels($context->tenant, $kind, $withArchived);
            $stages  = $this->pipeline->store->stages($context->tenant, array_map(static fn (Funnel $f): string => $f->getId(), $funnels));

            return array_map(static fn (Funnel $f): FunnelView => PipelineSupport::view($f, $stages), $funnels);
        });
    }

    /**
     * One funnel (archived included) with every stage.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function funnel(string $id): FunnelView
    {
        $context = $this->pipeline->support->access->context(CrmPermission::View);

        return $this->pipeline->support->read(function () use ($context, $id): FunnelView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $funnel = $this->pipeline->funnel($context, $id);

            return PipelineSupport::view($funnel, $this->pipeline->stages($context, $id));
        });
    }
}
