<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Api;

use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiRouteLoader;
use Nowo\CrmKitBundle\Tests\Support\CrmApiOpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_string;
use function strtoupper;

/**
 * `docs/openapi/crm-v1.yaml` is the generated document, and routes and document match both ways (0.3.0).
 */
final class ApiOpenApiTest extends TestCase
{
    public function testTheDocumentIsCurrent(): void
    {
        if (getenv('NOWO_WRITE_CONTRACT') === '1') {
            file_put_contents(CrmApiOpenApi::FILE, CrmApiOpenApi::yaml());
        }
        self::assertSame(CrmApiOpenApi::yaml(), file_get_contents(CrmApiOpenApi::FILE));
    }

    public function testRoutesAndDocumentMatchBothWays(): void
    {
        $document = Yaml::parseFile(CrmApiOpenApi::FILE);
        self::assertIsArray($document);
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $documented = [];
        foreach ($paths as $path => $methods) {
            self::assertIsArray($methods);
            foreach ($methods as $method => $spec) {
                self::assertIsArray($spec);
                $documented[strtoupper(is_string($method) ? $method : '') . ' ' . $path] = $spec['x-crm-operation'] ?? null;
            }
        }
        $routed     = [];
        $operations = [];
        foreach ((new CrmApiRouteLoader(true, '', true))->load('.')->all() as $route) {
            $routed[$route->getMethods()[0] . ' ' . $route->getPath()] = $route->getDefault(CrmApiRouteLoader::OPERATION_ATTRIBUTE);
            $operation                                                 = $route->getDefault(CrmApiRouteLoader::OPERATION_ATTRIBUTE);
            self::assertIsString($operation);
            $operations[$operation] = true;
        }
        self::assertEqualsCanonicalizing($routed, $documented);
        foreach (CrmApiOperation::cases() as $operation) {
            self::assertArrayHasKey($operation->value, $operations, 'every operation has a route');
        }
        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $record = $schemas['Record'] ?? null;
        self::assertIsArray($record);
        $properties = $record['properties'] ?? null;
        self::assertIsArray($properties);
        $text = $properties['text'] ?? null;
        self::assertIsArray($text);
        self::assertTrue($text['x-untrusted'] ?? false);
    }
}
