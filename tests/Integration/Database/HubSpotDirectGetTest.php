<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;

use function parse_str;
use function parse_url;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * 0.10.3 (Beacon D-20261124-05): the HubSpot steps of the import modal opened directly (e.g.
 * `/customers/import/hubspot/connect` from the address bar) land on the companies list with the modal reopened on
 * that step; a denied person keeps the 404; without `import.dialog.direct_get: redirect` nothing changes.
 */
final class HubSpotDirectGetTest extends HubSpotTestCase
{
    public function testEveryHubSpotStepReopensOnTheCompaniesList(): void
    {
        $fake = $this->hubspot(['import' => ['dialog' => ['direct_get' => 'redirect']]]);
        $this->connect($fake);
        $id = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Company]));
        foreach (['nowo_crm_kit_hubspot' => '/crm/import/hubspot', 'nowo_crm_kit_hubspot_connect' => '/crm/import/hubspot/connect', 'nowo_crm_kit_hubspot_new' => '/crm/import/hubspot/new', 'nowo_crm_kit_hubspot_mapping' => '/crm/import/hubspot/' . $id . '/mapping'] as $route => $path) {
            $this->client->request('GET', $path, [], [], ImportDirectGetTest::NAVIGATE);
            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $path);
            $location = (string) $this->client->getResponse()->headers->get('Location');
            self::assertSame('/crm/companies', parse_url($location, PHP_URL_PATH), $path);
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            $reopen = $query['_dialog'] ?? null;
            self::assertIsArray($reopen, $path);
            self::assertSame($route, $reopen['route'] ?? null);
            $dialog = $this->client->request('GET', $location)->filter('dialog[data-crm-import-dialog]');
            self::assertSame($path, $dialog->attr('data-nowo-ui-remote-form-reopen-url'), $path);
        }
        // the modal's own fetch of a step: the fragment, as before
        $this->client->request('GET', '/crm/import/hubspot/connect', [], [], ImportDirectGetTest::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('import-step', $this->client->getResponse()->headers->get('X-Nowo-Crm-Fragment'));

        // import without manage: connect stays a 404, never the redirect
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        $this->client->request('GET', '/crm/import/hubspot/connect', [], [], ImportDirectGetTest::NAVIGATE);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        // another tenant's import
        $this->ctx()->tenant = 'globex';
        $this->actAs('ana', 'view', 'edit', 'manage');
        $this->client->request('GET', '/crm/import/hubspot/' . $id . '/mapping', [], [], ImportDirectGetTest::NAVIGATE);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOffByDefault(): void
    {
        $fake = $this->hubspot();
        $this->connect($fake);
        $this->client->request('GET', '/crm/import/hubspot/connect', [], [], ImportDirectGetTest::NAVIGATE);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
