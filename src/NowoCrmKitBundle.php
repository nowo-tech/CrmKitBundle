<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle;

use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcMicroDateTimeImmutableType;
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

    /** Doctrine mapping name of the API's idempotency table (0.3.0; only with `api.enabled`). */
    public const API_MAPPING_NAME = 'NowoCrmKitBundleApi';

    /** Doctrine mapping name of the funnels (0.3.0; only with `pipeline.enabled`). */
    public const PIPELINE_MAPPING_NAME = 'NowoCrmKitBundlePipeline';

    /** Doctrine mapping name of the HubSpot import (0.10.0; only with `import.hubspot.enabled`). */
    public const HUBSPOT_MAPPING_NAME = 'NowoCrmKitBundleHubSpot';

    /** Public path of the bundle's assets after `assets:install` (REQ-ASSETS-004, the `nowo_crm_kit` asset package). */
    public const ASSETS_PATH = '/bundles/nowocrmkit';

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
     * Registers the `nowo_crm_kit` asset package, the XML mapping on the bundle's entity manager, the UTC type and the
     * FormKit profile.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('framework')) {
            // REQ-ASSETS-004 (0.4.0): the import modal's script, `asset('js/nowo-crm-import.js', 'nowo_crm_kit')`.
            $builder->prependExtensionConfig('framework', ['assets' => ['packages' => [self::ALIAS => ['base_path' => self::ASSETS_PATH]]]]);
        }
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
        $this->prependRowActions($builder);
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        $manager  = self::ALIAS;
        $pipeline = false;
        $api      = false;
        $hubspot  = false;
        foreach ($builder->getExtensionConfig(self::ALIAS) as $config) {
            $doctrine = is_array($config) && is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (array_key_exists('entity_manager', $doctrine)) {
                $manager = is_string($doctrine['entity_manager']) && $doctrine['entity_manager'] !== '' ? $doctrine['entity_manager'] : 'default';
            }
            if (is_array($config) && is_array($config['api'] ?? null) && array_key_exists('enabled', $config['api'])) {
                $api = $config['api']['enabled'] === true;
            }
            if (is_array($config) && is_array($config['pipeline'] ?? null) && array_key_exists('enabled', $config['pipeline'])) {
                $pipeline = $config['pipeline']['enabled'] === true;
            }
            $import = is_array($config) && is_array($config['import'] ?? null) ? $config['import'] : [];
            if (is_array($import['hubspot'] ?? null) && array_key_exists('enabled', $import['hubspot'])) {
                $hubspot = $import['hubspot']['enabled'] === true;
            }
        }
        $builder->prependExtensionConfig('doctrine', ['dbal' => ['types' => [UtcDateTimeImmutableType::NAME => UtcDateTimeImmutableType::class, UtcMicroDateTimeImmutableType::NAME => UtcMicroDateTimeImmutableType::class]]]);
        if ($manager !== 'default' && !$this->declares($builder, $manager)) {
            // The private entity manager is the host's to declare (the Flex recipe does): PrivateEntityManagerPass
            // fails the build with the fix instead of creating one silently.
            return;
        }
        $mappings = [self::MAPPING_NAME => ['type' => 'xml', 'dir' => __DIR__ . '/Resources/config/doctrine', 'prefix' => 'Nowo\\CrmKitBundle\\Domain\\Model', 'is_bundle' => false]];
        if ($pipeline) {
            // 0.3.0: the funnel tables are mapped only while `pipeline.enabled` (no table otherwise).
            $mappings[self::PIPELINE_MAPPING_NAME] = ['type' => 'xml', 'dir' => __DIR__ . '/Resources/config/doctrine-pipeline', 'prefix' => 'Nowo\\CrmKitBundle\\Domain\\Model\\Pipeline', 'is_bundle' => false];
        }
        if ($api) {
            // 0.3.0: the idempotency table is mapped only while `api.enabled`.
            $mappings[self::API_MAPPING_NAME] = ['type' => 'xml', 'dir' => __DIR__ . '/Resources/config/doctrine-api', 'prefix' => 'Nowo\\CrmKitBundle\\Domain\\Model\\Api', 'is_bundle' => false];
        }
        if ($hubspot) {
            // 0.10.0: the HubSpot connection and external reference tables only while `import.hubspot.enabled`.
            $mappings[self::HUBSPOT_MAPPING_NAME] = ['type' => 'xml', 'dir' => __DIR__ . '/Resources/config/doctrine-hubspot', 'prefix' => 'Nowo\\CrmKitBundle\\Domain\\Model\\HubSpot', 'is_bundle' => false];
        }
        $builder->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$manager => ['mappings' => $mappings]]]]);
    }

    /**
     * 0.6.0: with `ui.row_actions: menu`, the UiKit `row_actions` partial of this bundle's pages is the bundle's own
     * (the rows' secondary and destructive actions go into the row-actions menu). A host's own theme entry wins.
     */
    private function prependRowActions(ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('nowo_ui_kit')) {
            return;
        }
        $menu = false;
        foreach ($builder->getExtensionConfig(self::ALIAS) as $config) {
            if (is_array($config) && is_array($config['ui'] ?? null) && array_key_exists('row_actions', $config['ui'])) {
                $menu = $config['ui']['row_actions'] === 'menu';
            }
        }
        if ($menu) {
            $builder->prependExtensionConfig('nowo_ui_kit', ['theme' => ['bundles' => [self::TRANSLATION_DOMAIN => ['partials' => ['row_actions' => '@NowoCrmKit/partials/_row_actions.html.twig']]]]]);
        }
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
        /** @var array{doctrine: array{entity_manager: ?string}, import: array{sync_rows: int, chunk_rows: int}, api: array{http: array{prefix: string}}} $typed */
        $typed = $config;
        $parameters->set(self::ALIAS . '.import.sync_chunks', ServiceDefinitions::syncChunks($typed['import']['sync_rows'], $typed['import']['chunk_rows']));
        $parameters->set(self::ALIAS . '.api_prefix', $typed['api']['http']['prefix']);
        $parameters->set(self::ALIAS . '.api_path', $typed['api']['http']['prefix'] . '/crm/v1');
        $manager = $typed['doctrine']['entity_manager'];
        $builder->setAlias(
            self::ALIAS . '.entity_manager',
            is_string($manager) && $manager !== '' ? 'doctrine.orm.' . $manager . '_entity_manager' : 'doctrine.orm.entity_manager',
        )->setPublic(true);
        ServiceDefinitions::register($container, $config);
    }
}
