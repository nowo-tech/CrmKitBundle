<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle;

use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\CrmKitWebUiSecurityPass;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\CrmPortsPass;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ParameterFlattener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * A CRM for Symfony organisations, phase by phase (contract docs/CONTRACT.md). 0.1.0 ships phase A: companies and
 * contacts with custom fields, tags, owners, notes, an activity timeline, host links and panels on a 360° page, CSV
 * and vCard import, duplicate merging, GDPR export / erasure / consent / retention, plan-limit counters, the SearchKit
 * providers and the UiKit + FormKit pages, on a private entity manager. Tenant-aware and deny by default.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoCrmKitBundle extends AbstractBundle
{
    /** Configuration root and container parameter prefix. */
    public const ALIAS = 'nowo_crm_kit';

    /** Translation domain (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoCrmKitBundle';

    /** FormKit profile of the bundle's forms (REQ-TWIG-005 rule 11). */
    public const FORM_PROFILE = 'crm_kit';

    /** Doctrine mapping name of phase A. */
    public const MAPPING_NAME = 'NowoCrmKitBundle';

    protected string $extensionAlias = self::ALIAS;

    /**
     * The bundle's resources live in `src/Resources`.
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    /**
     * Adds the compiler passes and the autoconfiguration of the host extension points.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->registerForAutoconfiguration(CrmPanelProviderInterface::class)->addTag(CrmPanelProviderInterface::PANEL_TAG);
        $container->registerForAutoconfiguration(CrmHostObjectResolverInterface::class)->addTag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
        $container->addCompilerPass(new PrivateEntityManagerPass());
        $container->addCompilerPass(new CrmPortsPass());
        $container->addCompilerPass(new CrmKitWebUiSecurityPass());
    }

    /**
     * Defines the strict configuration tree (contract section 10).
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        ConfigurationTree::define($definition->rootNode());
    }

    /**
     * Registers the XML mapping on the bundle's entity manager, the UTC type and the FormKit profile.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('nowo_form_kit')) {
            $declared = false;
            foreach ($builder->getExtensionConfig('nowo_form_kit') as $config) {
                $declared = $declared || (is_array($config) && is_array($config['profiles'] ?? null) && array_key_exists(self::FORM_PROFILE, $config['profiles']));
            }
            if (!$declared) {
                $builder->prependExtensionConfig('nowo_form_kit', ['profiles' => [self::FORM_PROFILE => [
                    'alias'                           => self::FORM_PROFILE,
                    'translation_domain'              => self::TRANSLATION_DOMAIN,
                    'convention_requires_translation' => true,
                    'defaults'                        => ['attr' => ['class' => 'nowo-ui-input'], 'row_attr' => []],
                ]]]);
            }
        }
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        $manager = self::ALIAS;
        foreach ($builder->getExtensionConfig(self::ALIAS) as $config) {
            $doctrine = is_array($config) && is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (array_key_exists('entity_manager', $doctrine)) {
                $manager = is_string($doctrine['entity_manager']) && $doctrine['entity_manager'] !== '' ? $doctrine['entity_manager'] : 'default';
            }
        }
        $builder->prependExtensionConfig('doctrine', ['dbal' => ['types' => [UtcDateTimeImmutableType::NAME => UtcDateTimeImmutableType::class]]]);
        if ($manager !== 'default' && !$this->declares($builder, $manager)) {
            // The private entity manager is the host's to declare (the Flex recipe does): PrivateEntityManagerPass
            // fails the build with the fix instead of creating one silently.
            return;
        }
        $mapping = ['type' => 'xml', 'dir' => __DIR__ . '/Resources/config/doctrine', 'prefix' => 'Nowo\\CrmKitBundle\\Domain\\Model', 'is_bundle' => false];
        $builder->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$manager => ['mappings' => [self::MAPPING_NAME => $mapping]]]]]);
    }

    /**
     * Whether the host's Doctrine configuration declares the entity manager.
     */
    private function declares(ContainerBuilder $builder, string $manager): bool
    {
        foreach ($builder->getExtensionConfig('doctrine') as $doctrine) {
            $orm = is_array($doctrine) && is_array($doctrine['orm'] ?? null) ? $doctrine['orm'] : [];
            if (is_array($orm['entity_managers'] ?? null) && array_key_exists($manager, $orm['entity_managers'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Publishes the validated configuration as container parameters, aliases the entity manager and registers the
     * services.
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();
        foreach (ParameterFlattener::flatten(self::ALIAS, $config) as $name => $value) {
            $parameters->set($name, $value);
        }
        /** @var array{doctrine: array{entity_manager: ?string}, import: array{sync_rows: int, chunk_rows: int}} $typed */
        $typed = $config;
        $parameters->set(self::ALIAS . '.import.sync_chunks', ServiceDefinitions::syncChunks($typed['import']['sync_rows'], $typed['import']['chunk_rows']));
        $manager = $typed['doctrine']['entity_manager'];
        $builder->setAlias(
            self::ALIAS . '.entity_manager',
            is_string($manager) && $manager !== '' ? 'doctrine.orm.' . $manager . '_entity_manager' : 'doctrine.orm.entity_manager',
        )->setPublic(true);
        ServiceDefinitions::register($container, $config);
    }
}
