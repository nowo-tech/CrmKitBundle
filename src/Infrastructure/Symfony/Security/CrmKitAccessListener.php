<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `kernel.controller` on every route of the CRM (REQ-UI-002): the area check (`view`, i.e. `canAccess`) runs before
 * any action, so a new action cannot forget it; the action itself asks its own permission. Nobody signed in on a route
 * behind a firewall ⇒ Security's access-denied exception, which the firewall turns into its entry point (login redirect
 * or 401); else no tenant, CRM off or nobody acting ⇒ 404 and refused ⇒ 403 (through the bundle's exception listener).
 * Stateless.
 */
final readonly class CrmKitAccessListener
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmKitGuard $guard,
        private CrmAccessCheckerInterface $checker,
        private ?FirewallMap $firewalls = null,
    ) {
    }

    /**
     * Checks the request.
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) !== true) {
            return;
        }
        try {
            $this->guard->requireGranted(CrmPermission::View);
        } catch (NotAvailableException $e) {
            if (!$this->checker->currentActor() instanceof ActorRef && $this->firewalls?->getFirewallConfig($request) instanceof FirewallConfig) {
                throw new AccessDeniedException('Sign in to use the CRM.', $e);
            }

            throw $e;
        }
    }
}
