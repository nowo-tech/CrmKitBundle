<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function array_map;
use function range;
use function str_repeat;

/**
 * The strict configuration tree (contract section 10).
 */
final class ConfigurationTreeTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);
        self::assertSame('nowo_crm_kit', $config['doctrine']['entity_manager'] ?? null);
        self::assertSame(['marketing_email', 'newsletter', 'tracking'], $config['gdpr']['purposes'] ?? null);
        self::assertFalse($config['ui']['enabled'] ?? true);
        self::assertSame(['customer', 'supplier', 'partner'], $config['roles']['catalogue'] ?? null);
        self::assertSame(['billing', 'orders', 'technical', 'general'], $config['affiliations']['purposes'] ?? null);
        self::assertSame([], $this->process(['roles' => ['catalogue' => []]])['roles']['catalogue'] ?? null);
        self::assertSame(['customer', 'distributor', 'r_2'], $this->process(['roles' => ['catalogue' => ['customer', 'distributor', 'r_2']]])['roles']['catalogue'] ?? null);
    }

    public function testHubSpotIsOffByDefaultAndNeedsASecretBox(): void
    {
        $config = $this->process([]);
        /** @var array{hubspot: array{enabled: bool}, messenger: array{enabled: bool}, max_rows: int} $import */
        $import = $config['import'];
        self::assertFalse($import['hubspot']['enabled']);
        self::assertSame(5000, $import['max_rows'], 'the import keys stay');
        self::assertFalse($import['messenger']['enabled']);
        self::assertSame(['box' => null, 'keys' => [], 'current_key' => null, 'doctrine_encrypt' => ['profile' => null, 'key_id' => 'doctrine_encrypt', 'previous' => []]], $config['secrets'] ?? null);
        self::assertSame(['profile' => 'crm_2026', 'key_id' => 'de2026', 'previous' => ['doctrine_encrypt' => null, 'de2025' => 'crm_2025']], $this->process(['secrets' => ['doctrine_encrypt' => ['profile' => 'crm_2026', 'key_id' => 'de2026', 'previous' => ['doctrine_encrypt' => null, 'de2025' => 'crm_2025']]]])['secrets']['doctrine_encrypt'] ?? null);
        self::assertSame('my.box', $this->process(['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['box' => 'my.box']])['secrets']['box'] ?? null);
        self::assertSame(['k1' => 'x'], $this->process(['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['keys' => ['k1' => 'x'], 'current_key' => 'k1']])['secrets']['keys'] ?? null);
    }

    public function testTabIconsTakeOnlyTheAreasTheHostNames(): void
    {
        self::assertSame([], $this->process([])['ui']['tab_icons'] ?? null, 'the built-in set lives in UiOptions, not in the tree');
        self::assertSame(['deals' => 'tabler:currency-euro'], $this->process(['ui' => ['tab_icons' => ['deals' => 'tabler:currency-euro']]])['ui']['tab_icons'] ?? null);
        self::assertSame(['funnels' => '', 'tags' => null, 'contacts' => 'users'], $this->process(['ui' => ['tab_icons' => ['funnels' => '', 'tags' => null, 'contacts' => 'users']]])['ui']['tab_icons'] ?? null);
    }

    public function testListPerPageAndRecordCreateAreOptIn(): void
    {
        $config = $this->process([]);
        self::assertSame([], $config['ui']['list_per_page'] ?? null, 'no rows-per-page select by default');
        self::assertSame('page', $config['ui']['record_create'] ?? null);
        $config = $this->process(['ui' => ['list_per_page' => [10, 25, 50, 100], 'record_create' => 'dialog']]);
        self::assertSame([10, 25, 50, 100], $config['ui']['list_per_page'] ?? null);
        self::assertSame('dialog', $config['ui']['record_create'] ?? null);
        self::assertSame('dialog_inline', $this->process(['ui' => ['admin_create' => 'dialog_inline']])['ui']['admin_create'] ?? null, 'the deprecated 0.6.1 dialog');
        self::assertSame([5, 15], $this->process(['ui' => ['page_size' => 15, 'list_per_page' => [5, 15]]])['ui']['list_per_page'] ?? null);
    }

    public function testPipelineFormsStayInlineByDefault(): void
    {
        self::assertSame('inline', $this->process([])['ui']['pipeline_forms'] ?? null);
        self::assertSame('dialog', $this->process(['ui' => ['pipeline_forms' => 'dialog']])['ui']['pipeline_forms'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'per page below 5' => [['ui' => ['list_per_page' => [4, 25]]], 'ui.list_per_page'];
        yield 'per page above 100' => [['ui' => ['list_per_page' => [25, 200]]], 'ui.list_per_page'];
        yield 'per page duplicate' => [['ui' => ['list_per_page' => [25, 25]]], 'ui.list_per_page'];
        yield 'too many per page' => [['ui' => ['list_per_page' => range(10, 65, 5)]], 'ui.list_per_page'];
        yield 'per page without the page size' => [['ui' => ['list_per_page' => [10, 50]]], 'ui.page_size must be one of ui.list_per_page'];
        yield 'record create' => [['ui' => ['record_create' => 'modal']], 'record_create'];
        yield 'pipeline forms' => [['ui' => ['pipeline_forms' => 'modal']], 'pipeline_forms'];
        yield 'prefix' => [['doctrine' => ['table_prefix' => 'Bad-']], 'table_prefix'];
        yield 'path' => [['ui' => ['path' => 'crm']], 'ui.path'];
        yield 'purpose' => [['gdpr' => ['purposes' => ['Marketing']]], 'gdpr.purposes'];
        yield 'duplicate purpose' => [['gdpr' => ['purposes' => ['a', 'a']]], 'gdpr.purposes'];
        yield 'role uppercase' => [['roles' => ['catalogue' => ['Customer']]], 'roles.catalogue'];
        yield 'role trailing newline' => [['roles' => ['catalogue' => ["customer\n"]]], 'roles.catalogue'];
        yield 'role too long' => [['roles' => ['catalogue' => [str_repeat('a', 33)]]], 'roles.catalogue'];
        yield 'role digit first' => [['roles' => ['catalogue' => ['1st']]], 'roles.catalogue'];
        yield 'role duplicate' => [['roles' => ['catalogue' => ['customer', 'customer']]], 'roles.catalogue'];
        yield 'role not a string' => [['roles' => ['catalogue' => [5]]], 'roles.catalogue'];
        yield 'too many roles' => [['roles' => ['catalogue' => array_map(static fn (int $i): string => 'r' . $i, range(1, 33))]], 'roles.catalogue'];
        yield 'purpose dot' => [['affiliations' => ['purposes' => ['bill.ing']]], 'affiliations.purposes'];
        yield 'purpose duplicate' => [['affiliations' => ['purposes' => ['billing', 'billing']]], 'affiliations.purposes'];
        yield 'no settings role' => [['security' => ['settings_roles' => []]], 'security.settings_roles'];
        yield 'no export role' => [['security' => ['export_roles' => []]], 'security.export_roles'];
        yield 'no erase role' => [['security' => ['erase_roles' => []]], 'security.erase_roles'];
        yield 'phase' => [['reports' => ['enabled' => true]], 'phase G'];
        yield 'tab icon of an unknown area' => [['ui' => ['tab_icons' => ['reports' => 'tabler:chart']]], 'ui.tab_icons'];
        yield 'tab icon class string' => [['ui' => ['tab_icons' => ['companies' => 'ti ti-building']]], 'ui.tab_icons'];
        yield 'tab icon uppercase' => [['ui' => ['tab_icons' => ['companies' => 'tabler:Building']]], 'ui.tab_icons'];
        yield 'tab icon markup' => [['ui' => ['tab_icons' => ['companies' => '"><script>']]], 'ui.tab_icons'];
        yield 'unknown' => [['nope' => true], 'nope'];
        yield 'hubspot without a secret box' => [['import' => ['hubspot' => ['enabled' => true]]], 'needs a secret box'];
        yield 'hubspot current key not listed' => [['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['keys' => ['k1' => 'x'], 'current_key' => 'k2']], 'needs a secret box'];
        yield 'secret key id' => [['secrets' => ['keys' => ['Bad Id' => 'x']]], 'secrets.keys'];
        yield 'secret key empty' => [['secrets' => ['keys' => ['k1' => '']]], 'secrets.keys'];
        yield 'doctrine encrypt key id' => [['secrets' => ['doctrine_encrypt' => ['key_id' => 'Bad Id']]], 'secrets.doctrine_encrypt.key_id'];
        yield 'doctrine encrypt previous key id' => [['secrets' => ['doctrine_encrypt' => ['previous' => ['Bad Id' => 'old']]]], 'secrets.doctrine_encrypt.previous'];
        yield 'doctrine encrypt previous profile' => [['secrets' => ['doctrine_encrypt' => ['previous' => ['old' => '']]]], 'secrets.doctrine_encrypt.previous'];
        yield 'doctrine encrypt sealing key listed as previous' => [['secrets' => ['doctrine_encrypt' => ['key_id' => 'de2026', 'previous' => ['de2026' => 'old']]]], 'cannot also be listed'];
        yield 'hubspot browse limit' => [['import' => ['hubspot' => ['browse_rate_limit' => ['limit' => 0]]]], 'browse_rate_limit'];
        yield 'hubspot page size' => [['import' => ['hubspot' => ['page_size' => 500]]], 'page_size'];
        yield 'hubspot guard' => [['import' => ['hubspot' => ['outbound_url_guard' => 'maybe']]], 'outbound_url_guard'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>>
     */
    private function process(array $config): array
    {
        $tree = new TreeBuilder('nowo_crm_kit');
        ConfigurationTree::define($tree->getRootNode());

        /** @var array<string, array<string, mixed>> $processed */
        $processed = (new Processor())->process($tree->buildTree(), [$config]);

        return $processed;
    }
}
