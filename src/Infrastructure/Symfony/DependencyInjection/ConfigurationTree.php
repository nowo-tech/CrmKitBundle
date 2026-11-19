<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\ConsentPurpose;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

use function array_unique;
use function count;
use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * The strict configuration tree of `nowo_crm_kit` (REQ-SF-006, contract section 10): unknown keys fail, every key has
 * its documented default. The switches of the later phases exist and refuse `true` until their release.
 */
final class ConfigurationTree
{
    /** Phases after A: switch => release that brings it. */
    public const LATER_PHASES = ['pipeline' => '0.2.0', 'quotes' => '0.3.0', 'invoices' => '0.4.0', 'campaigns' => '0.5.0', 'portal' => '0.6.0', 'reports' => '0.7.0'];

    /**
     * Fills the root node.
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $children = $root->addDefaultsIfNotSet()->children();
        foreach (['tenant_resolver', 'access_checker', 'feature_gate', 'plan_limits', 'actor_directory', 'audit_sink', 'clock'] as $port) {
            $children->scalarNode($port)->defaultNull()->info('Service id of the host implementation; null = the bundle default.')->end();
        }
        $roles = $children->arrayNode('access')->addDefaultsIfNotSet()->children()->arrayNode('roles')->addDefaultsIfNotSet()->info('RoleMapAccessChecker only: permission => roles; every list empty = denied.')->children();
        foreach (CrmPermission::cases() as $permission) {
            $roles->arrayNode($permission->value)->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([])->end();
        }
        $roles->end()->end()->end()->end();
        $children->arrayNode('feature')->addDefaultsIfNotSet()->children()
            ->booleanNode('default_enabled')->defaultTrue()->info('ConfiguredFeatureGate: the CRM is on for every tenant.')->end()
        ->end()->end();
        $children->arrayNode('limits')->addDefaultsIfNotSet()->info('ConfiguredPlanLimits: maximum active records per tenant; null = unlimited.')->children()
            ->integerNode('companies')->defaultNull()->min(0)->end()
            ->integerNode('contacts')->defaultNull()->min(0)->end()
        ->end()->end();
        $children->arrayNode('doctrine')->addDefaultsIfNotSet()->children()
            ->scalarNode('entity_manager')->defaultValue('nowo_crm_kit')->info('The private entity manager (the Flex recipe declares it); null = the default entity manager with explicit transactions.')->end()
            ->scalarNode('table_prefix')->defaultValue('nowo_')
                ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/\A[a-z][a-z0-9_]{0,15}\z/D', $v) !== 1)->thenInvalid('table_prefix must be 1-16 characters of a-z, 0-9 or "_", starting with a letter.')->end()
            ->end()
        ->end()->end();
        $children->arrayNode('fields')->addDefaultsIfNotSet()->children()
            ->integerNode('max_per_subject')->defaultValue(50)->min(1)->max(200)->end()
        ->end()->end();
        $children->arrayNode('import')->addDefaultsIfNotSet()->children()
            ->integerNode('max_rows')->defaultValue(5000)->min(1)->max(50000)->end()
            ->integerNode('max_bytes')->defaultValue(5242880)->min(1024)->max(52428800)->end()
            ->integerNode('sync_rows')->defaultValue(500)->min(10)->max(50000)->end()
            ->integerNode('chunk_rows')->defaultValue(100)->min(10)->max(1000)->end()
            ->integerNode('stale_claim_seconds')->defaultValue(600)->min(60)->max(86400)->end()
        ->end()->end();
        $children->arrayNode('gdpr')->addDefaultsIfNotSet()->children()
            ->arrayNode('purposes')->scalarPrototype()->end()->defaultValue(['marketing_email', 'newsletter', 'tracking'])
                ->validate()->ifTrue(static fn (array $v): bool => !self::purposes($v))->thenInvalid('gdpr.purposes are distinct keys of 1-64 characters of a-z, 0-9, "_" or ".", starting with a letter.')->end()
            ->end()
        ->end()->end();
        $children->arrayNode('search')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->info('SearchKit providers when SearchKit is installed (and the UI is on).')->end()
        ->end()->end();
        $children->arrayNode('ui')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultFalse()->info('Routes are opt-in (REQ-UI-001).')->end()
            ->scalarNode('path')->defaultValue('/crm')
                ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || !str_starts_with($v, '/'))->thenInvalid('ui.path starts with "/".')->end()
            ->end()
            ->integerNode('page_size')->defaultValue(25)->min(10)->max(100)->end()
            ->scalarNode('layout')->defaultNull()->info('Twig layout of the pages; null = UiKit admin layout.')->end()
        ->end()->end();
        foreach (self::LATER_PHASES as $phase => $release) {
            $children->arrayNode($phase)->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()
                    ->validate()->ifTrue(static fn (bool $v): bool => $v)->thenInvalid('nowo_crm_kit.' . $phase . ' arrives with ' . $release . '; this release ships phase A only.')->end()
                ->end()
            ->end()->end();
        }
    }

    /**
     * Whether a value is a list of distinct valid purposes.
     */
    /**
     * @param array<mixed> $value
     */
    private static function purposes(array $value): bool
    {
        foreach ($value as $purpose) {
            if (!is_string($purpose) || preg_match(ConsentPurpose::PATTERN, $purpose) !== 1) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }
}
