<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;

/**
 * REQ-UI-002 after every extension is loaded: `security.allow_unauthenticated` is refused in the prod environment and
 * turns resource scoping off (one shared actor); the pages (`ui.enabled`) need SecurityBundle unless it is set; then, without SecurityBundle and without a host area
 * checker, every area is open ({@see AllowAllCrmKitAccessChecker}): no role can be asked. `security.access_checker`
 * points {@see CrmKitAccessCheckerInterface} at the host's service.
 *
 * @internal
 */
final class CrmKitWebUiSecurityPass implements CompilerPassInterface
{
    /**
     * Checks and wires.
     */
    public function process(ContainerBuilder $container): void
    {
        $p       = ServiceDefinitions::P;
        $checker = $container->getParameter($p . 'security.access_checker');
        $allow   = $container->getParameter($p . 'security.allow_unauthenticated') === true;
        $secured = $container->has('security.authorization_checker');
        if ($allow && $container->hasParameter('kernel.environment') && $container->getParameter('kernel.environment') === 'prod') {
            throw new InvalidConfigurationException('nowo_crm_kit.security.allow_unauthenticated is for development and demos only: it is refused when kernel.environment is prod.');
        }
        if ($allow) {
            // One shared actor ("anonymous") for everybody: per-owner scoping would mean nothing.
            $container->setParameter($p . 'security.resource_scoping', false);
        }
        if ($container->getParameter($p . 'ui.enabled') === true && !$secured && !$allow) {
            throw new InvalidConfigurationException('nowo_crm_kit.ui.enabled requires symfony/security-bundle (security.authorization_checker), or set nowo_crm_kit.security.allow_unauthenticated: true (dev/demo only, never in production).');
        }
        if (is_string($checker) && $checker !== '') {
            $container->setAlias(CrmKitAccessCheckerInterface::class, $checker)->setPublic(true);
        } elseif ($allow && !$secured) {
            $container->setAlias(CrmKitAccessCheckerInterface::class, AllowAllCrmKitAccessChecker::class)->setPublic(true);
        }
    }
}
