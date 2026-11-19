<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection;

use Nowo\CrmKitBundle\Domain\Value\AffiliationPurpose;
use Nowo\CrmKitBundle\Domain\Value\ConsentPurpose;
use Nowo\CrmKitBundle\Domain\Value\LostReasonKey;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

use function array_unique;
use function count;
use function is_array;
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
    public const LATER_PHASES = ['quotes' => '0.5.0', 'invoices' => '0.6.0', 'portal' => '0.7.0', 'campaigns' => '0.10.0', 'reports' => 'phase G (after 0.10.0)'];

    /** Built-in party roles (`distributor` is translated too, but opt-in). */
    public const DEFAULT_ROLES = ['customer', 'supplier', 'partner'];

    /** Built-in affiliation purposes. */
    public const DEFAULT_PURPOSES = ['billing', 'orders', 'technical', 'general'];

    /** Built-in lost reasons of the funnels (0.3.0; labels `lost_reason.<key>`). */
    public const DEFAULT_LOST_REASONS = ['price', 'timing', 'competitor', 'no_decision', 'other'];

    /** Most keys per catalogue. */
    public const MAX_KEYS = 32;

    /**
     * Fills the root node.
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $children = $root->addDefaultsIfNotSet()->children();
        foreach (['tenant_resolver', 'feature_gate', 'plan_limits', 'actor_directory', 'audit_sink', 'clock'] as $port) {
            $children->scalarNode($port)->defaultNull()->info('Service id of the host implementation; null = the bundle default.')->end();
        }
        $security = $children->arrayNode('security')->addDefaultsIfNotSet()->info('REQ-UI-002: who may use the CRM. Each *_roles list grants its area to any of its roles; an empty list (access, manage, amounts, campaigns only) means any authenticated user.')->children();
        $security->arrayNode('access_roles')->info('Opening the CRM (lists, 360° pages, search); [] = any authenticated user.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->end();
        $security->scalarNode('access_checker')->defaultNull()->info('Service id of a host CrmKitAccessCheckerInterface; null = ConfigurableCrmKitAccessChecker (these role lists).')->end();
        $security->scalarNode('actor_resolver')->defaultNull()->info('Service id of a host CrmActorResolverInterface (the stable actor id of a signed-in person, e.g. a user UUID, and names per page); null = IdentifierActorResolver (getUserIdentifier()).')->end();
        $security->booleanNode('allow_unauthenticated')->defaultFalse()->info('DEV/DEMO only: the pages without SecurityBundle or without signing in (one shared actor "anonymous"; resource_scoping is then off). Refused when kernel.environment is prod.')->end();
        $security->arrayNode('manage_roles')->info('Create, edit, trash / restore, import; [] = any authenticated user.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->end();
        $security->arrayNode('settings_roles')->info('Tags, custom fields, per-tenant settings, purge from the trash; with resource_scoping, every record. At least one role.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->requiresAtLeastOneElement()->end();
        $security->arrayNode('export_roles')->info('CSV export of lists, GDPR export of a contact. At least one role.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->requiresAtLeastOneElement()->end();
        $security->arrayNode('erase_roles')->info('GDPR erasure of a contact. At least one role.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->requiresAtLeastOneElement()->end();
        $security->arrayNode('amounts_roles')->info('Deal amounts, quote and invoice totals, revenue figures (phase B on); [] = any authenticated user.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->end();
        $security->arrayNode('campaigns_roles')->info('Sending informative circulars (phase E2 on); [] = any authenticated user.')->scalarPrototype()->cannotBeEmpty()->end()->defaultValue(['ROLE_ADMIN'])->end();
        $security->booleanNode('resource_scoping')->defaultFalse()->info('true: a record is open by default only to its owner (or when it has none) and to settings_roles; nowo_crm_kit.record_access_check listeners decide the rest.')->end();
        $security->end()->end();
        $children->arrayNode('feature')->addDefaultsIfNotSet()->children()
            ->booleanNode('default_enabled')->defaultTrue()->info('ConfiguredFeatureGate: the CRM is on for every tenant.')->end()
        ->end()->end();
        $children->arrayNode('limits')->addDefaultsIfNotSet()->info('ConfiguredPlanLimits: maximum active records per tenant; null = unlimited.')->children()
            ->integerNode('companies')->defaultNull()->min(0)->end()
            ->integerNode('contacts')->defaultNull()->min(0)->end()
            ->integerNode('deals')->defaultNull()->min(0)->info('0.3.0: deals not in the trash.')->end()
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
        $children->arrayNode('roles')->addDefaultsIfNotSet()->children()
            ->arrayNode('catalogue')->info('Party role keys a company or contact may hold (several at once); labels: role.<key> in the NowoCrmKitBundle domain. Built in: customer, supplier, partner; distributor is translated but opt-in.')
                ->scalarPrototype()->end()->defaultValue(self::DEFAULT_ROLES)
                ->validate()->ifTrue(static fn (array $v): bool => !self::keys($v, RoleKey::PATTERN))->thenInvalid('roles.catalogue holds at most 32 distinct keys of 1-32 characters of a-z, 0-9 or "_", starting with a letter.')->end()
            ->end()
        ->end()->end();
        $children->arrayNode('affiliations')->addDefaultsIfNotSet()->children()
            ->arrayNode('purposes')->info('What a contact can be a company\'s contact for (several per affiliation); labels: purpose.<key> in the NowoCrmKitBundle domain.')
                ->scalarPrototype()->end()->defaultValue(self::DEFAULT_PURPOSES)
                ->validate()->ifTrue(static fn (array $v): bool => !self::keys($v, AffiliationPurpose::PATTERN))->thenInvalid('affiliations.purposes holds at most 32 distinct keys of 1-32 characters of a-z, 0-9 or "_", starting with a letter.')->end()
            ->end()
        ->end()->end();
        $children->arrayNode('search')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->info('SearchKit groups crm_companies, crm_contacts and (with pipeline.enabled) crm_deals, when SearchKit >= 1.9 (PHP >= 8.3) is installed and the UI is on; switch one group off with nowo_search_kit.groups.<key>.')->end()
        ->end()->end();
        $children->arrayNode('ui')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultFalse()->info('Routes are opt-in (REQ-UI-001).')->end()
            ->scalarNode('path')->defaultValue('/crm')
                ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || !str_starts_with($v, '/'))->thenInvalid('ui.path starts with "/".')->end()
            ->end()
            ->integerNode('page_size')->defaultValue(25)->min(10)->max(100)->end()
            ->scalarNode('layout')->defaultNull()->info('Twig layout of the pages; null = UiKit admin layout.')->end()
        ->end()->end();
        self::pipeline($children);
        self::api($children);
        foreach (self::LATER_PHASES as $phase => $release) {
            $children->arrayNode($phase)->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()
                    ->validate()->ifTrue(static fn (bool $v): bool => $v)->thenInvalid('nowo_crm_kit.' . $phase . ' arrives with ' . $release . '; this release ships companies, contacts, roles, purposes and funnels only.')->end()
                ->end()
            ->end()->end();
        }
    }

    /**
     * The API (0.3.0): operations for in-process callers (an MCP host) and the off-by-default `/crm/v1` JSON API.
     */
    private static function api(NodeBuilder $children): void
    {
        $api = $children->arrayNode('api')->addDefaultsIfNotSet()->info('0.3.0: the Api\Operation layer (an MCP host needs only this) and the /crm/v1 JSON API.')
            ->validate()->ifTrue(static fn (array $v): bool => is_array($v['http'] ?? null) && ($v['http']['enabled'] ?? false) === true && ($v['enabled'] ?? false) !== true)->thenInvalid('nowo_crm_kit.api.http.enabled needs nowo_crm_kit.api.enabled.')->end()
            ->children();
        $api->booleanNode('enabled')->defaultFalse()->info('The operations, the principal and the idempotency table.')->end();
        $api->integerNode('page_size')->defaultValue(25)->min(10)->max(100)->info('Default page size of the keyset lists (at most 100).')->end();
        $api->integerNode('idempotency_ttl_seconds')->defaultValue(86400)->min(3600)->max(604800)->info('How long an Idempotency-Key is kept.')->end();
        $http = $api->arrayNode('http')->addDefaultsIfNotSet()->children();
        $http->booleanNode('enabled')->defaultFalse()->info('The /crm/v1 routes (route type nowo_crm_kit_api).')->end();
        $http->scalarNode('prefix')->defaultValue('/api')
            ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('#\A(?:/[a-z0-9_-]+)+\z#', $v) !== 1)->thenInvalid('api.http.prefix is a path like /api: segments of a-z, 0-9, "_" or "-", no trailing slash.')->end()
        ->end();
        $http->scalarNode('authenticator')->defaultNull()->info('Service id of a CrmApiAuthenticatorInterface; null = SecurityUserCrmApiAuthenticator (the firewall user + default_grant).')->end();
        $http->scalarNode('tenant_resolver')->defaultNull()->info('Service id of a CrmApiTenantResolverInterface; null = the configured tenant resolver.')->end();
        $grant = $http->arrayNode('default_grant')->addDefaultsIfNotSet()->info('What SecurityUserCrmApiAuthenticator grants a firewall user.')->children();
        $grant->enumNode('level')->values(['view', 'edit', 'manage'])->defaultValue('view')->end();
        $grant->booleanNode('amounts')->defaultFalse()->end();
        $grant->booleanNode('consents')->defaultFalse()->end();
        $grant->booleanNode('destructive')->defaultFalse()->end();
        $grant->end()->end();
        $http->end()->end();
        $api->end()->end();
    }

    /**
     * The funnels (0.3.0): off by default; the deal funnels, lifecycle funnels, statistics and next actions.
     */
    private static function pipeline(NodeBuilder $children): void
    {
        $pipeline = $children->arrayNode('pipeline')->addDefaultsIfNotSet()->info('0.3.0: funnels with per-stage statistics, deals and next actions (tables and routes only while enabled).')->children();
        $pipeline->booleanNode('enabled')->defaultFalse()->end();
        $pipeline->arrayNode('lost_reasons')->info('Lost reason keys (labels lost_reason.<key> in the NowoCrmKitBundle domain); moving a deal to a lost stage needs one.')
            ->scalarPrototype()->end()->defaultValue(self::DEFAULT_LOST_REASONS)->requiresAtLeastOneElement()
            ->validate()->ifTrue(static fn (array $v): bool => !self::keys($v, LostReasonKey::PATTERN))->thenInvalid('pipeline.lost_reasons holds at most 32 distinct keys of 1-32 characters of a-z, 0-9 or "_", starting with a letter.')->end()
        ->end();
        $pipeline->scalarNode('task_port')->defaultNull()->info('Service id of a host CrmTaskPort for next actions; null = the TaskBoard bridge when taskboard.board_id is set, else the built-in list.')->end();
        $pipeline->arrayNode('taskboard')->addDefaultsIfNotSet()->info('TaskBoard bridge (nowo-tech/task-board-bundle installed): next actions become tasks on this board.')->children()
            ->scalarNode('board_id')->defaultNull()->info('The board new tasks go to; null = no bridge.')->end()
            ->scalarNode('column')->defaultNull()->info('The column (TaskBoard status name) new tasks go to; null = the board\'s first.')->end()
        ->end()->end();
        $pipeline->end()->end();
    }

    /**
     * Whether a value is a list of at most {@see self::MAX_KEYS} distinct strings matching the pattern.
     *
     * @param array<mixed> $value
     */
    private static function keys(array $value, string $pattern): bool
    {
        foreach ($value as $key) {
            if (!is_string($key) || preg_match($pattern, $key) !== 1) {
                return false;
            }
        }

        return count($value) <= self::MAX_KEYS && count(array_unique($value)) === count($value);
    }

    /**
     * Whether a value is a list of distinct valid purposes.
     *
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
