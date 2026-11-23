<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Service\HubSpotConnectionServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HubSpotImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;

use function array_map;
use function array_replace_recursive;

/**
 * Base of the HubSpot tests (0.10.0): the kit with `import.hubspot.enabled`, a sodium key made for the test, and the
 * kit's HubSpot client decorated by a MockHttpClient over {@see FakeHubSpot} (fake data and a fake credential only).
 */
abstract class HubSpotTestCase extends CrmTestCase
{
    /** A test key (never a real one). */
    protected const KEY = "\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff\x00";

    /**
     * Boots the kit with HubSpot on and every grant for `ana`; the fake account.
     *
     * @param array<string, mixed> $config
     */
    protected function hubspot(array $config = []): FakeHubSpot
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['import' => ['hubspot' => ['enabled' => true, 'page_size' => 10]], 'secrets' => ['keys' => ['k1' => self::KEY], 'current_key' => 'k1']], $config);
        $this->bootWithUiKit($merged, [], static function (ServicesConfigurator $s): void {
            $s->set('test.hubspot', FakeHubSpot::class)->public();
            $s->set('test.hubspot_client', MockHttpClient::class)->decorate('nowo_crm_kit.hubspot.http_client')->args([new Reference('test.hubspot'), 'https://api.hubapi.com']);
        });
        $this->admin();
        $fake = $this->kernel->getContainer()->get('test.hubspot');
        self::assertInstanceOf(FakeHubSpot::class, $fake);

        return $fake;
    }

    protected function connections(): HubSpotConnectionServiceInterface
    {
        return $this->service(HubSpotConnectionServiceInterface::class);
    }

    protected function hubImports(): HubSpotImportServiceInterface
    {
        return $this->service(HubSpotImportServiceInterface::class);
    }

    protected function imports(): ImportServiceInterface
    {
        return $this->service(ImportServiceInterface::class);
    }

    /**
     * Connects the fake account.
     */
    protected function connect(FakeHubSpot $fake): int
    {
        return $this->connections()->connect($fake->token, null, null)->version;
    }

    /**
     * Starts and confirms an import with the default mapping; its id.
     *
     * @param list<HubSpotObject> $objects
     * @param list<string> $lists
     * @param array<array-key, string> $listTags list id => tag id or create
     * @param array<string, array<string, string>>|null $properties
     */
    protected function startAndConfirm(array $objects = [HubSpotObject::Contact], array $lists = [], array $listTags = [], DuplicatePolicy $policy = DuplicatePolicy::Skip, ?array $properties = null): string
    {
        $id     = $this->hubImports()->start(new HubSpotImportScope($objects, array_map(static fn (string $l): HubSpotObjectId => new HubSpotObjectId($l), $lists)), $policy);
        $schema = $this->hubImports()->schema($id);
        $map    = [];
        foreach ($schema->properties as $object => $rows) {
            foreach ($rows as $row) {
                if ($row->target !== '') {
                    $map[$object][$row->name] = $row->target;
                }
            }
        }
        $this->hubImports()->confirm($id, new HubSpotMapping($properties ?? $map, $listTags), $policy, $schema->version);

        return $id;
    }
}
