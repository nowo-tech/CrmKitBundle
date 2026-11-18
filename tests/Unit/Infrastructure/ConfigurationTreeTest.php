<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

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
        yield 'phase' => [['reports' => ['enabled' => true]], '0.7.0'];
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
