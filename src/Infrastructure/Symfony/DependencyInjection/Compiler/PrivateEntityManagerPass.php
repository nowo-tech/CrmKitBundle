<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function sprintf;

/**
 * Checks that the entity manager named by `doctrine.entity_manager` exists (the Flex recipe declares
 * `nowo_crm_kit`), so a missing declaration fails the container build with the fix, not at the first query.
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * Runs the check.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('doctrine')) {
            throw new LogicException('nowo_crm_kit needs DoctrineBundle with the ORM: install and enable doctrine/doctrine-bundle.');
        }
        $name = $container->getParameter('nowo_crm_kit.doctrine.entity_manager');
        if (!is_string($name) || $name === '') {
            return;
        }
        $id = 'doctrine.orm.' . $name . '_entity_manager';
        if (!$container->has($id)) {
            throw new LogicException(sprintf('nowo_crm_kit.doctrine.entity_manager is "%s" but doctrine.orm.entity_managers.%s is not declared: add it (the Flex recipe does) or set nowo_crm_kit.doctrine.entity_manager to null to use the default entity manager with explicit transactions.', $name, $name));
        }
    }
}
