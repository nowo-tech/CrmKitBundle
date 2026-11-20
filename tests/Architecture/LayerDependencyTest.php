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

    public function testTheApiImportsNoFrameworkAndNoOuterLayer(): void
    {
        self::assertSame([], $this->violations('/Api/', '/^(Symfony|Doctrine|Nowo\\\\CrmKitBundle\\\\(Infrastructure|Bridge))\\\\/'));
    }

    public function testNoOperationUsesTheSystemApisOrTheUnexposedServices(): void
    {
        // Owner rules (0.3.0): the batch lookups and the actor personal data are system APIs without permission
        // checks; consent changes, merges, erasure, purge, imports, exports, settings and campaigns are never exposed.
        $forbidden = '/(CrmBatchLookup|CrmBatchLookupStoreInterface|CrmActorPersonalData|ActorPersonalData|ConsentService|MergeService|CrmPersonalData|ImportService|CrmExporter|SettingsService|FieldService|HostLinkService|RecordPurger|FunnelService)(Interface)?$/';
        self::assertSame([], $this->violations('/Api/', $forbidden));
        self::assertSame([], $this->violations('/Application/Pipeline/', '/(CrmBatchLookup|CrmBatchLookupStoreInterface|CrmActorPersonalData)(Interface)?$/'));
    }

    public function testNoOperationRestoresOrPurges(): void
    {
        // Owner rules (0.3.0): restoring from the trash and purging stay on the pages (DealService::restore() / purge(),
        // RecordService::restore() / purge()); an API trash is undone only by a person.
        $violations = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (str_starts_with($path, '/Api/') && preg_match('/->(restore|purge)\(/', $source) === 1) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
    }

    public function testOnlyTheApiLayerOfInfrastructureImportsTheHttpFoundationForTheApi(): void
    {
        $violations = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (str_contains($source, 'Nowo\\CrmKitBundle\\Api\\') && !str_starts_with($path, '/Api/') && !str_starts_with($path, '/Infrastructure/') && !str_starts_with($path, '/Application/')) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations);
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
