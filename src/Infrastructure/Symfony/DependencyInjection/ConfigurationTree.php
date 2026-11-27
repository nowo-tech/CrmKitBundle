<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection;

use Nowo\CrmKitBundle\Domain\Value\AffiliationPurpose;
use Nowo\CrmKitBundle\Domain\Value\CallOutcomeKey;
use Nowo\CrmKitBundle\Domain\Value\ConsentPurpose;
use Nowo\CrmKitBundle\Domain\Value\LostReasonKey;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiOptions;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\HttpClient\HttpClient;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
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

    /** Built-in call outcomes of the activities (0.11.0; labels `call_outcome.<key>`). */
    public const DEFAULT_CALL_OUTCOMES = ['connected', 'left_voicemail', 'no_answer', 'busy', 'wrong_number'];

    /** Most keys per catalogue. */
    public const MAX_KEYS = 32;

    /** A secret key id (anchored, `/D`), as SealedSecret::KEY_ID_PATTERN. */
    private const KEY_ID = '/\A[a-z0-9_-]{1,16}\z/D';

    /**
     * Fills the root node.
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $children = $root->addDefaultsIfNotSet()->children();
        foreach (['tenant_resolver', 'feature_gate', 'plan_limits', 'actor_directory', 'audit_sink', 'clock', 'actor_email_lookup'] as $port) {
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
        $import = $children->arrayNode('import')->addDefaultsIfNotSet()->children();
        $import->integerNode('max_rows')->defaultValue(5000)->min(1)->max(50000)->end();
        $import->integerNode('max_bytes')->defaultValue(5242880)->min(1024)->max(52428800)->end();
        $import->integerNode('sync_rows')->defaultValue(500)->min(10)->max(50000)->end();
        $import->integerNode('chunk_rows')->defaultValue(100)->min(10)->max(1000)->end();
        $import->integerNode('stale_claim_seconds')->defaultValue(600)->min(60)->max(86400)->end();
        $import->arrayNode('dialog')->addDefaultsIfNotSet()->info('0.10.3: the import modal of the lists.')->children()
            ->enumNode('direct_get')->values(['off', 'redirect'])->defaultValue('off')->info('0.10.3 (UiKit 2.74 remote_dialog.direct_get, Beacon D-20261124-05): what a step URL of the import modal (CSV / vCard file, mapping, preview and result; with import.hubspot.enabled the HubSpot source, connect, start and mapping) does when it is opened directly (address bar, bookmark, a link from another site). off (default) = its page, as before; redirect = after the same access checks (a denied person keeps the 404) a 302 to the companies or contacts list with the modal reopened on that step. Links followed inside the app keep the page (the no-JS fallback); dialog fetches and POSTs are unchanged. Needs ui.enabled.')->end()
        ->end()->end();
        self::hubspot($children, $import);
        $import->end()->end();
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
            ->enumNode('row_actions')->values(['buttons', 'menu'])->defaultValue('buttons')->info('0.6.0: buttons = every row action as a button (as before); menu = Open and Edit stay as icon buttons, the others (Restore, Move to trash) go into the UiKit row-actions menu, Delete last.')->end()
            ->enumNode('header_actions')->values(['toolbar', 'split'])->defaultValue('toolbar')->info('0.6.0: how the list header actions (New, Export CSV, Import) render when the host does not read page.header.actions itself: toolbar (as before) or split (UiKit View\\HeaderActions: one primary button, the rest in the more menu). Always View\\Action objects in page.header.actions; never a second page-actions menu.')->end()
            ->enumNode('record_actions')->values(['split', 'toolbar'])->defaultValue('split')->info('0.6.0: record page header: split = Edit as an icon button and Merge, vCard, Export, Trash and Erase in the more menu (destructive last); toolbar = every action as a button (as before).')->end()
            ->booleanNode('record_tabs')->defaultTrue()->info('0.6.0: show the list tabs (Companies, Contacts, Tags...) under the record page header; false hides them on record pages.')->end()
            ->booleanNode('filters_fold')->defaultFalse()->info('0.6.0: fold toggle on the filters card of the lists (UiKit ListPage filtersFold).')->end()
            ->scalarNode('filters_slot')->defaultNull()->info('0.6.0: Twig template of the host that wraps the filters region (e.g. a bottom sheet on phones). It receives page, form (the filter FormView) and filters (the rendered UiKit filters markup). null = no wrapper.')->end()
            ->booleanNode('list_tabs')->defaultTrue()->info('0.6.1: show the area tabs (Companies, Contacts, Tags, Custom fields, Settings...) on the list, admin and form pages; false hides them there. The company / contact record page follows record_tabs.')->end()
            ->enumNode('admin_create')->values(['inline', 'dialog', 'dialog_inline'])->defaultValue('inline')->info('0.6.1: how Tags and Custom fields offer create. inline = the create form in a card under the list (as before); dialog (0.8.0: on demand) = a View\\Action in page.header.actions (New tag, New field for companies, New field for contacts), a real link to the page with ?new=1 (the no-JS fallback, which shows the form card) that loads the form into a UiKit remote form dialog (X-Nowo-Dialog: 1 answers the fragment, 422 in the dialog, 204 + X-Nowo-Redirect once saved); dialog_inline (deprecated since 0.8.0, removed in 1.0) = the 0.6.1 - 0.7 dialog whose form is rendered in the page (admin/_create.html.twig).')->end()
            ->enumNode('settings_width')->values(['form', 'full'])->defaultValue('form')->info('0.6.1: the Settings form. form = the UiKit form panel (the host form column width, as before); full = the bundle card at the full content width, like the lists.')->end()
            ->arrayNode('list_per_page')->info('0.8.0: the "Rows per page" choices of the lists (companies, contacts, Tags, Custom fields), e.g. [10, 25, 50, 100]: a select[name=per_page] in the filter card. A per_page outside the list falls back to page_size. [] (default) = no select (the filter card is unchanged) and the per_page values UiKit accepts (10, 25, 50, 100) plus page_size. 1-10 distinct values from 5 to 100 that include page_size.')
                ->integerPrototype()->end()
                ->defaultValue([])
                ->validate()->ifTrue(static fn (array $v): bool => !self::perPage($v))->thenInvalid('ui.list_per_page holds 1-10 distinct integers from 5 to 100; got %s.')->end()
            ->end()
            ->enumNode('record_create')->values(['page', 'dialog'])->defaultValue('page')->info('0.8.0: how "New company" / "New contact" open. page = the form page (as before); dialog = the header action (a real link to the form page, the no-JS fallback) loads that page on demand into a UiKit remote form dialog (X-Nowo-Dialog: 1 answers the fragment, an invalid submission 422 in the dialog, success 204 + X-Nowo-Redirect to the new record). Same access checks and CSRF token as the page.')->end()
            ->enumNode('pipeline_forms')->values(['inline', 'dialog'])->defaultValue('inline')->info('0.9.0 (pipeline.enabled): how the funnel pages offer their forms and confirms. inline = as in 0.3 - 0.8: the move-to-stage and next action forms in the deal page, the lifecycle and next action forms in the 360° page, the new funnel form under the funnels, a stage edit form per stage and the new stage form in the funnel page, the trash / purge confirms of a deal in the page (deprecated: dialog becomes the default in 1.0); dialog = every one of them is its own page (GET; the no-JS fallback and a deep link) opened on demand into one UiKit remote form dialog per page by a real link (X-Nowo-Dialog: 1 answers the fragment, an invalid submission 422 in the dialog, success 204 + X-Nowo-Redirect), so a page renders no form it has not opened. Same access checks, tenant, CSRF tokens and version checks as the inline forms.')->end()
            ->arrayNode('filters_more')->info('0.10.2 (Beacon filter standard): per list, the filter fields folded under the "More filters" disclosure after the Filter / Clear buttons (UiKit FilterField more; it shows how many of them are set and stays open while one is). Lists: companies, contacts, deals; [] for a list = no fold there. Fields: companies ' . implode(', ', UiOptions::FILTER_FIELDS['companies']) . '; contacts ' . implode(', ', UiOptions::FILTER_FIELDS['contacts']) . '; deals ' . implode(', ', UiOptions::FILTER_FIELDS['deals']) . '. Defaults: the request\'s (rarer filters folded).')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('companies')->scalarPrototype()->end()->defaultValue(UiOptions::DEFAULT_FILTERS_MORE['companies'])->end()
                    ->arrayNode('contacts')->scalarPrototype()->end()->defaultValue(UiOptions::DEFAULT_FILTERS_MORE['contacts'])->end()
                    ->arrayNode('deals')->scalarPrototype()->end()->defaultValue(UiOptions::DEFAULT_FILTERS_MORE['deals'])->end()
                ->end()
                ->validate()->ifTrue(static fn (array $v): bool => !UiOptions::filtersMoreValid($v))->thenInvalid('ui.filters_more names a field a list does not have, or one twice (see the fields of each list); got %s.')->end()
            ->end()
            ->enumNode('per_page_position')->values(['filters', 'pagination'])->defaultValue('filters')->info('0.10.2 (with list_per_page): where the "Rows per page" select goes. filters = compact, the first item of the filter actions, right before Filter / Clear (UiKit FilterField pager); pagination = in the UiKit pagination bar instead (the filter form has no per_page field).')->end()
            ->enumNode('search_width')->values(['xs', 'sm', 'md', 'lg', 'full'])->defaultNull()->info('0.10.2: the width of the search field in the filter grid (UiKit FilterField width). null (default) = one grid column like the other fields; lg = two columns (0.10.1 and before); full = the whole row.')->end()
            ->scalarNode('per_page_row_class')->defaultNull()->info('0.10.2: extra CSS classes on the row of the compact "Rows per page" select (UiKit FilterField rowClass), e.g. a host pager class ("issue-filters__pager"). null = none.')
                ->validate()->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match('/^[A-Za-z0-9_-]+(?: [A-Za-z0-9_-]+)*$/D', $v) !== 1))->thenInvalid('ui.per_page_row_class holds CSS class names separated by single spaces; got %s.')->end()
            ->end()
            ->arrayNode('tab_icons')->info('0.7.0: the icon of an area tab, by area (' . implode(', ', array_keys(UiOptions::DEFAULT_TAB_ICONS)) . '), merged over the built-in Tabler set (companies: tabler:building, contacts: tabler:users, deals: tabler:briefcase, tags: tabler:tags, fields: tabler:forms, settings: tabler:settings, funnels: tabler:filter). An icon name "[prefix:]name" as UiKit takes it; "" or null = no icon (the tab shows its label in every tab style). Name only the areas you change. Decoration only: the access checks decide which tabs show.')
                ->useAttributeAsKey('area')->normalizeKeys(false)
                ->scalarPrototype()->end()
                ->defaultValue([])
                ->validate()->ifTrue(static fn (array $v): bool => !self::tabIcons($v))->thenInvalid('ui.tab_icons maps an area (' . implode(', ', array_keys(UiOptions::DEFAULT_TAB_ICONS)) . ') to an icon name "[prefix:]name" of lowercase letters, digits and single hyphens (e.g. "tabler:building"), "" or null; got %s.')->end()
            ->end()
        ->end()
            ->validate()->ifTrue(static fn (array $v): bool => is_array($v['list_per_page'] ?? null) && $v['list_per_page'] !== [] && !in_array($v['page_size'] ?? null, $v['list_per_page'], true))->thenInvalid('ui.page_size must be one of ui.list_per_page; got %s.')->end()
        ->end();
        self::pipeline($children);
        self::activities($children);
        self::api($children);
        $root->validate()->ifTrue(static fn (array $v): bool => self::hubspotOn($v) && !class_exists(HttpClient::class))->thenInvalid('nowo_crm_kit.import.hubspot.enabled needs symfony/http-client: composer require symfony/http-client.')->end();
        $root->validate()->ifTrue(static fn (array $v): bool => self::hubspotOn($v) && !self::hasSecretBox($v))->thenInvalid('nowo_crm_kit.import.hubspot.enabled needs a secret box: set secrets.box, or secrets.keys with secrets.current_key one of them.')->end();
        foreach (self::LATER_PHASES as $phase => $release) {
            $children->arrayNode($phase)->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()
                    ->validate()->ifTrue(static fn (bool $v): bool => $v)->thenInvalid('nowo_crm_kit.' . $phase . ' arrives with ' . $release . '; this release ships companies, contacts, roles, purposes and funnels only.')->end()
                ->end()
            ->end()->end();
        }
    }

    /**
     * The secret box (0.10.0) and the HubSpot import source with the optional background run (design section 6).
     */
    private static function hubspot(NodeBuilder $children, NodeBuilder $import): void
    {
        $secrets = $children->arrayNode('secrets')->addDefaultsIfNotSet()->info('0.10.0: the box that seals the kit\'s secrets (the HubSpot token).')->children();
        $secrets->scalarNode('box')->defaultNull()->info('Service id of a CrmSecretBoxInterface (e.g. Nowo\\CrmKitBundle\\Bridge\\DoctrineEncrypt\\DoctrineEncryptCrmSecretBox); null = SodiumCrmSecretBox with the keys below.')->end();
        $secrets->arrayNode('keys')->info('Key id (1-16 of a-z, 0-9, "_", "-") => 32-byte key, from the environment: \'%env(base64:CRM_KIT_SECRET_KEY_2026)%\'. Every key opens; current_key seals.')
            ->useAttributeAsKey('id')->normalizeKeys(false)->scalarPrototype()->end()->defaultValue([])
            ->validate()->ifTrue(static fn (array $v): bool => !self::keyIds($v))->thenInvalid('secrets.keys maps key ids of 1-16 characters of a-z, 0-9, "_" or "-" to keys.')->end()
        ->end();
        $secrets->scalarNode('current_key')->defaultNull()->info('The key id that seals; the other keys only open (rotation: nowo:crm-kit:secrets:rotate).')->end();
        $doctrine = $secrets->arrayNode('doctrine_encrypt')->addDefaultsIfNotSet()->info('0.10.1: profiles of the DoctrineEncrypt box (secrets.box: DoctrineEncryptCrmSecretBox, when the kit defines it). Rotation: CONFIGURATION, "Key rotation".')->children();
        $doctrine->scalarNode('profile')->defaultNull()->info('DoctrineEncrypt profile that seals (null: its default profile). Halite or Defuse; anything else fails the build.')->end();
        $doctrine->scalarNode('key_id')->defaultValue('doctrine_encrypt')->info('Key id stored with new seals (1-16 of a-z, 0-9, "_", "-"); a new one with each new profile.')
            ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(self::KEY_ID, $v) !== 1)->thenInvalid('secrets.doctrine_encrypt.key_id is 1-16 characters of a-z, 0-9, "_" or "-".')->end()
        ->end();
        $doctrine->arrayNode('previous')->info('Key id => DoctrineEncrypt profile (~: the default one) of tokens sealed before a rotation; they keep opening until nowo:crm-kit:secrets:rotate re-sealed them.')
            ->useAttributeAsKey('id')->normalizeKeys(false)->scalarPrototype()->end()->defaultValue([])
            ->validate()->ifTrue(static fn (array $v): bool => !self::profileMap($v))->thenInvalid('secrets.doctrine_encrypt.previous maps key ids of 1-16 characters of a-z, 0-9, "_" or "-" to DoctrineEncrypt profiles (~ for the default one).')->end()
        ->end();
        $doctrine->end()->validate()->ifTrue(static fn (array $v): bool => is_string($v['key_id'] ?? null) && is_array($v['previous'] ?? null) && array_key_exists($v['key_id'], $v['previous']))->thenInvalid('secrets.doctrine_encrypt.key_id seals: it cannot also be listed in secrets.doctrine_encrypt.previous.')->end()->end();
        $secrets->end()->end();
        $hubspot = $import->arrayNode('hubspot')->addDefaultsIfNotSet()->info('0.10.0: import companies, contacts, owners and lists from HubSpot (a private app token per tenant, read-only scopes).')->children();
        $hubspot->booleanNode('enabled')->defaultFalse()->info('Needs a secret box (secrets.box, or secrets.keys + current_key) and symfony/http-client; maps the HubSpot tables and routes.')->end();
        $hubspot->integerNode('page_size')->defaultValue(100)->min(10)->max(100)->info('Objects per HubSpot page (HubSpot\'s maximum is 100).')->end();
        $hubspot->integerNode('pages_per_request')->defaultValue(2)->min(1)->max(20)->info('Pages a web request settles before "Continue", the resume command or the background run.')->end();
        $hubspot->integerNode('max_records')->defaultValue(50000)->min(1)->max(500000)->info('Most objects per kind per run; beyond, the import fails with hubspot_too_many.')->end();
        $hubspot->enumNode('outbound_url_guard')->values(['auto', 'required', 'off'])->defaultValue('auto')->info('Pin api.hubapi.com to the public address OutboundUrlGuard resolves: auto = when nowo-tech/outbound-url-guard-bundle is installed, required = fail the build without it, off.')->end();
        $hubspot->arrayNode('http')->addDefaultsIfNotSet()->children()
            ->integerNode('timeout')->defaultValue(10)->min(1)->max(120)->info('Idle timeout in seconds.')->end()
            ->integerNode('max_duration')->defaultValue(30)->min(1)->max(600)->info('Seconds per request.')->end()
            ->integerNode('max_response_bytes')->defaultValue(8388608)->min(65536)->max(67108864)->info('Largest body read; a larger one is hubspot_bad_response.')->end()
        ->end()->end();
        $hubspot->arrayNode('rate_limit')->addDefaultsIfNotSet()->children()
            ->integerNode('max_attempts')->defaultValue(5)->min(1)->max(10)->info('Attempts per request on 429 / 5xx in the resume command and the background run (a web request never waits).')->end()
            ->integerNode('max_retry_after')->defaultValue(60)->min(1)->max(3600)->info('Longest wait in seconds; a longer one (the daily limit) pauses the import until then.')->end()
        ->end()->end();
        $hubspot->arrayNode('connect_rate_limit')->addDefaultsIfNotSet()->info('Token checks per tenant (valid or not).')->children()
            ->integerNode('limit')->defaultValue(5)->min(1)->max(100)->end()
            ->integerNode('interval')->defaultValue(600)->min(60)->max(86400)->info('Window in seconds.')->end()
        ->end()->end();
        $hubspot->arrayNode('browse_rate_limit')->addDefaultsIfNotSet()->info('0.10.1: HubSpot reads of the start and mapping pages per tenant (each view and each start asks HubSpot; the portal\'s daily quota is shared). Beyond it the page says to wait.')->children()
            ->integerNode('limit')->defaultValue(60)->min(1)->max(1000)->end()
            ->integerNode('interval')->defaultValue(600)->min(60)->max(86400)->info('Window in seconds.')->end()
        ->end()->end();
        $hubspot->end()->end();
        $import->arrayNode('messenger')->addDefaultsIfNotSet()->info('0.10.0 (REQ-MSG-001): run imports (any format) in the background with Symfony Messenger.')->children()
            ->booleanNode('enabled')->defaultFalse()->info('true: confirm and "Continue" dispatch RunCrmImport instead of running in the request (route it to an async transport).')->end()
            ->integerNode('units')->defaultValue(20)->min(1)->max(1000)->info('Chunks (HubSpot: pages) per message; the handler dispatches the next message.')->end()
        ->end()->end();
    }

    /**
     * Whether `import.hubspot.enabled` is true in a validated root.
     *
     * @param array<mixed> $root
     */
    private static function hubspotOn(array $root): bool
    {
        $import = is_array($root['import'] ?? null) ? $root['import'] : [];

        return is_array($import['hubspot'] ?? null) && ($import['hubspot']['enabled'] ?? false) === true;
    }

    /**
     * Whether a secret box is configured: a service id, or keys with a current key among them.
     *
     * @param array<mixed> $root
     */
    private static function hasSecretBox(array $root): bool
    {
        $secrets = is_array($root['secrets'] ?? null) ? $root['secrets'] : [];
        if (is_string($secrets['box'] ?? null) && $secrets['box'] !== '') {
            return true;
        }
        $keys = is_array($secrets['keys'] ?? null) ? $secrets['keys'] : [];

        return is_string($secrets['current_key'] ?? null) && array_key_exists($secrets['current_key'], $keys);
    }

    /**
     * Whether every key of the map is a key id and every value a DoctrineEncrypt profile name or null.
     *
     * @param array<mixed> $value
     */
    private static function profileMap(array $value): bool
    {
        foreach ($value as $id => $profile) {
            if (preg_match(self::KEY_ID, (string) $id) !== 1 || ($profile !== null && (!is_string($profile) || $profile === ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every key of the map is a key id.
     *
     * @param array<mixed> $value
     */
    private static function keyIds(array $value): bool
    {
        foreach ($value as $id => $key) {
            if (preg_match(self::KEY_ID, (string) $id) !== 1 || !is_string($key) || $key === '') {
                return false;
            }
        }

        return true;
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
     * The logged activities (0.11.0): off by default; calls, meetings and e-mails, the activity feed, pinned notes,.
     *
     * @mentions, the host activity source, the TaskBoard calendar source and the `.ics` download.
     */
    private static function activities(NodeBuilder $children): void
    {
        $activities = $children->arrayNode('activities')->addDefaultsIfNotSet()->info('0.11.0: logged calls, meetings and e-mails with the activity feed of a record (filter by kind and person), pinned notes, @mentions and meetings on TaskBoard\'s calendar (tables and routes only while enabled).')->children();
        $activities->booleanNode('enabled')->defaultFalse()->end();
        $activities->arrayNode('call_outcomes')->info('Call outcome keys (labels call_outcome.<key> in the NowoCrmKitBundle domain).')
            ->scalarPrototype()->end()->defaultValue(self::DEFAULT_CALL_OUTCOMES)
            ->validate()->ifTrue(static fn (array $v): bool => !self::keys($v, CallOutcomeKey::PATTERN))->thenInvalid('activities.call_outcomes holds at most 32 distinct keys of 1-32 characters of a-z, 0-9 or "_", starting with a letter.')->end()
        ->end();
        $activities->integerNode('max_pinned')->defaultValue(1)->min(0)->max(20)->info('Most pinned notes per record; pinning one more unpins the oldest. 0 = no limit (as before 0.11.0).')->end();
        $activities->scalarNode('host_source')->defaultNull()->info('Service id of a host CrmHostActivitySourceInterface (tasks, time entries, linked mail, tickets on the feed); null = none.')->end();
        $activities->scalarNode('audience')->defaultNull()->info('Service id of a host CrmRecordAudienceInterface (who may view a record: mention candidates and recipients); null = the default rule of security.resource_scoping.')->end();
        $activities->scalarNode('notifier')->defaultNull()->info('Service id of a host CrmNotifierInterface; null = the NotifyKit bridge when NotifyKit (>= 0.3) is installed, else no notifications.')->end();
        $activities->arrayNode('mentions')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->info('@mentions in notes: MilkdownEditor (>= 1.19) as the note field with the CRM\'s candidates, and notifications to the mentioned members.')->end()
            ->integerNode('max_per_note')->defaultValue(20)->min(1)->max(50)->info('Most members one save of a note notifies.')->end()
            ->arrayNode('rate_limit')->addDefaultsIfNotSet()->info('Calls of the mention endpoints (candidates and names) per person; beyond it 429 with Retry-After.')->children()
                ->integerNode('limit')->defaultValue(60)->min(1)->max(1000)->end()
                ->integerNode('interval')->defaultValue(60)->min(60)->max(3600)->info('Seconds (rounded up to minutes).')->end()
            ->end()->end()
        ->end()->end();
        $activities->arrayNode('notify_kit')->addDefaultsIfNotSet()->children()
            ->scalarNode('recipient_type')->defaultValue('user')->info('NotifyKit\'s RecipientRef type of the CRM\'s actor ids.')
                ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $v) !== 1)->thenInvalid('activities.notify_kit.recipient_type is 1-32 of a-z, 0-9 or "_", starting with a letter; got %s.')->end()
            ->end()
        ->end()->end();
        $activities->booleanNode('taskboard_calendar')->defaultTrue()->info('Meetings on TaskBoard\'s calendar (a CalendarEventSourceInterface source, when TaskBoard >= 2.24 is installed).')->end();
        $activities->end()->end();
    }

    /**
     * Whether a value is a list of 1-10 distinct integers from 5 to 100 (`ui.list_per_page`, 0.8.0).
     *
     * @param array<mixed> $value
     */
    private static function perPage(array $value): bool
    {
        foreach ($value as $size) {
            if (!is_int($size) || $size < 5 || $size > 100) {
                return false;
            }
        }

        return count($value) <= 10 && count(array_unique($value)) === count($value);
    }

    /**
     * Whether a value maps known areas to icon names, "" or null (`ui.tab_icons`).
     *
     * @param array<mixed> $value
     */
    private static function tabIcons(array $value): bool
    {
        foreach ($value as $area => $icon) {
            if (!array_key_exists($area, UiOptions::DEFAULT_TAB_ICONS)) {
                return false;
            }
            if ($icon !== null && $icon !== '' && (!is_string($icon) || preg_match(UiOptions::ICON_PATTERN, $icon) !== 1)) {
                return false;
            }
        }

        return true;
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
