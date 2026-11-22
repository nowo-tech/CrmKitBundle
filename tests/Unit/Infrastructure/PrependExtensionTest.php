<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use FilesystemIterator;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\UiKitBundle\DependencyInjection\NowoUiKitExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function str_contains;

/**
 * What the bundle prepends: the ORM mappings never use the `alias` key (deprecated in DoctrineBundle 3.4, removed in 4)
 * and no mapped class or DQL name uses the `Alias:Entity` shorthand; the UiKit row-actions partial only with
 * `ui.row_actions: menu`.
 */
final class PrependExtensionTest extends TestCase
{
    public function testTheMappingsHaveNoAliasKeyAndEveryClassIsAFqcn(): void
    {
        $builder = $this->builder(['doctrine' => ['entity_manager' => 'default'], 'api' => ['enabled' => true], 'pipeline' => ['enabled' => true]]);
        $builder->registerExtension(new DoctrineExtension());
        $this->prepend($builder);
        $mappings = [];
        foreach ($builder->getExtensionConfig('doctrine') as $config) {
            $orm = is_array($config) && is_array($config['orm'] ?? null) ? $config['orm'] : [];
            foreach (is_array($orm['entity_managers'] ?? null) ? $orm['entity_managers'] : [] as $manager) {
                $mappings += is_array($manager) && is_array($manager['mappings'] ?? null) ? $manager['mappings'] : [];
            }
        }
        self::assertSame([NowoCrmKitBundle::MAPPING_NAME, NowoCrmKitBundle::PIPELINE_MAPPING_NAME, NowoCrmKitBundle::API_MAPPING_NAME], array_keys($mappings));
        foreach ($mappings as $name => $mapping) {
            self::assertIsArray($mapping);
            self::assertFalse(array_key_exists('alias', $mapping), $name . ' declares no alias (DoctrineBundle 3.4 deprecation)');
            self::assertIsString($mapping['prefix']);
            self::assertStringContainsString('\\', $mapping['prefix'], $name . ' maps a namespace, not a short alias');
        }
    }

    public function testNoSourceUsesTheAliasEntityShorthand(): void
    {
        $offenders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), ['php', 'xml'], true)) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            if (preg_match('/[\'"\s(]NowoCrmKitBundle[A-Za-z]*:[A-Z][A-Za-z]+/', $text) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders, 'use FQCNs, not Alias:Entity');
    }

    public function testTheRowActionsPartialIsPrependedOnlyForTheMenu(): void
    {
        $default = $this->builder([]);
        $default->registerExtension(new NowoUiKitExtension());
        $this->prepend($default);
        self::assertSame([], $default->getExtensionConfig('nowo_ui_kit'));

        $menu = $this->builder(['ui' => ['row_actions' => 'menu']]);
        $menu->registerExtension(new NowoUiKitExtension());
        $this->prepend($menu);
        $config = $menu->getExtensionConfig('nowo_ui_kit');
        self::assertCount(1, $config);
        self::assertSame(['theme' => ['bundles' => [NowoCrmKitBundle::TRANSLATION_DOMAIN => ['partials' => ['row_actions' => '@NowoCrmKit/partials/_row_actions.html.twig']]]]], $config[0]);

        $buttons = $this->builder(['ui' => ['row_actions' => 'buttons']]);
        $buttons->registerExtension(new NowoUiKitExtension());
        $this->prepend($buttons);
        self::assertSame([], $buttons->getExtensionConfig('nowo_ui_kit'));
    }

    public function testWithoutUiKitNothingIsPrependedForTheMenu(): void
    {
        $builder = $this->builder(['ui' => ['row_actions' => 'menu']]);
        $this->prepend($builder);
        self::assertFalse($builder->hasExtension('nowo_ui_kit'));
        self::assertTrue(str_contains(NowoCrmKitBundle::ALIAS, 'crm'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function builder(array $config): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        if ($config !== []) {
            $builder->prependExtensionConfig(NowoCrmKitBundle::ALIAS, $config);
        }

        return $builder;
    }

    private function prepend(ContainerBuilder $builder): void
    {
        $loader     = new PhpFileLoader($builder, new FileLocator(__DIR__));
        $instanceof = [];
        (new NowoCrmKitBundle())->prependExtension(new ContainerConfigurator($builder, $loader, $instanceof, __FILE__, __FILE__), $builder);
    }
}
