<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Bridge\SearchKit\CrmSearchProvider;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function interface_exists;
use function is_string;

/**
 * Points the port aliases at the host's services and wires the optional infrastructure (contract sections 8 and 10):
 * a configured service id replaces each default; transaction-doctrine's write unit and conflict policy when its bundle
 * is registered (else a write unit of the bundle's own and the 409 fallback listener); the clock; the SearchKit
 * providers when SearchKit is installed, the UI is on and `search.enabled`.
 *
 * @internal
 */
final class CrmPortsPass implements CompilerPassInterface
{
    /**
     * Wires the ports.
     */
    public function process(ContainerBuilder $container): void
    {
        $p = ServiceDefinitions::P;
        foreach ([
            'tenant_resolver' => CrmTenantResolverInterface::class,
            'access_checker'  => CrmAccessCheckerInterface::class,
            'feature_gate'    => CrmFeatureGateInterface::class,
            'plan_limits'     => CrmPlanLimitsInterface::class,
            'actor_directory' => CrmActorDirectoryInterface::class,
            'audit_sink'      => CrmAuditSinkInterface::class,
        ] as $port => $interface) {
            $id = $container->getParameter($p . $port);
            if (is_string($id) && $id !== '') {
                $container->setAlias($interface, $id);
            }
        }
        $clock = $container->getParameter($p . 'clock');
        // FrameworkBundle (required) always defines `clock`.
        $container->setAlias($p . 'clock', is_string($clock) && $clock !== '' ? $clock : 'clock');
        if ($container->has('nowo_transaction_doctrine.write_unit')) {
            $container->setAlias($p . 'write_unit', 'nowo_transaction_doctrine.write_unit');
        } else {
            $container->setDefinition($p . 'write_unit', (new Definition(WriteUnit::class))->setArguments([new Reference('doctrine')]));
        }
        if (!$container->has('nowo_transaction_doctrine.conflict_responder')) {
            $container->setDefinition(ConflictFallbackListener::class, (new Definition(ConflictFallbackListener::class))
                ->setArguments([new Definition(ConflictResponsePolicy::class)])
                ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 64]));
        }
        if ($container->getParameter($p . 'ui.enabled') === true && $container->getParameter($p . 'search.enabled') === true && interface_exists(SearchProviderInterface::class) && $container->has('router') && $container->has('translator')) {
            foreach ([SubjectType::Company, SubjectType::Contact] as $i => $type) {
                $container->setDefinition($p . 'search.' . $type->value, (new Definition(CrmSearchProvider::class))
                    ->setArguments([$type, new Reference(CrmReader::class), new Reference('router'), new Reference('translator')])
                    ->addTag('nowo_search_kit.provider', ['priority' => 60 - $i])
                    ->setPublic(true));
            }
        }
    }
}
