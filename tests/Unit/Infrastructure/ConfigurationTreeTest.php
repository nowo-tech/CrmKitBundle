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

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
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
        yield 'phase' => [['reports' => ['enabled' => true]], 'phase G'];
        yield 'unknown' => [['nope' => true], 'nope'];
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
