<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;

/**
 * Layer rules of contract section 4 (REQ-ARCH-001), checked on the source text:
 *
 * - `Domain` imports no Symfony, Doctrine or HTTP class and nothing from `Application`, `Infrastructure` or `Bridge`;
 * - `Application` imports no Symfony or Doctrine class (PSR and the transaction contracts only);
 * - only `Bridge` imports optional packages (SearchKit).
 */
final class LayerDependencyTest extends TestCase
{
    public function testTheDomainImportsNoFrameworkAndNoOuterLayer(): void
    {
        self::assertSame([], $this->violations('/Domain/', '/^(Symfony|Doctrine|Psr\\\\Http|Nowo\\\\CrmKitBundle\\\\(Application|Infrastructure|Bridge))\\\\/'));
    }

    public function testTheApplicationImportsNoFramework(): void
    {
        self::assertSame([], $this->violations('/Application/', '/^(Symfony|Doctrine|Nowo\\\\CrmKitBundle\\\\(Infrastructure|Bridge))\\\\/'));
    }

    public function testOnlyBridgesImportOptionalPackages(): void
    {
        $violations = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (!str_starts_with($path, '/Bridge/') && !str_contains($path, 'Compiler/CrmPortsPass.php')) {
                foreach ($this->imports($source) as $import) {
                    if (str_starts_with($import, 'Nowo\\SearchKitBundle\\')) {
                        $violations[] = $path . ': ' . $import;
                    }
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testTheDetectorFlagsAForbiddenImport(): void
    {
        self::assertSame(['Symfony\\Component\\HttpFoundation\\Request'], $this->imports("<?php\nnamespace X;\n\nuse Symfony\\Component\\HttpFoundation\\Request;\n"));
        self::assertNotSame([], $this->sourceFiles());
    }

    /**
     * @return list<string> "path: import" per violation
     */
    private function violations(string $layer, string $pattern): array
    {
        $violations = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (!str_starts_with($path, $layer)) {
                continue;
            }
            foreach ($this->imports($source) as $import) {
                if (preg_match($pattern, $import) === 1) {
                    $violations[] = $path . ': ' . $import;
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function imports(string $source): array
    {
        preg_match_all('/^use\s+(?!function\s|const\s)([\w\\\\]+)/m', $source, $matches);

        return $matches[1];
    }

    /**
     * @return array<string, string> path relative to src/ => source text
     */
    private function sourceFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/src';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), '.php')) {
                $path                                 = str_replace('\\', '/', $file->getPathname());
                $files[str_replace($root, '', $path)] = (string) file_get_contents($path);
            }
        }

        return $files;
    }
}
