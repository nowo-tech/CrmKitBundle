<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use FilesystemIterator;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;
use Twig\Source;

use function array_replace_recursive;
use function file_get_contents;
use function str_ends_with;

/**
 * Twig lint of every template of the bundle (what `lint:twig` checks: tokens and syntax against the real extensions of
 * the kernel, UiKit's included).
 */
final class TwigTemplatesTest extends KernelTestCase
{
    public function testEveryTemplateParses(): void
    {
        $host = Host::standard();
        /** @var array<string, mixed> $config */
        $config = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true]]);
        $test   = $this->bootKernel($host->bundleConfig($config), $host->configure())->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $test);
        $twig = $test->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $count = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../src/Resources/views', FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $twig->parse($twig->tokenize(new Source((string) file_get_contents($file->getPathname()), $file->getFilename(), $file->getPathname())));
            ++$count;
        }
        self::assertGreaterThan(20, $count);
    }
}
