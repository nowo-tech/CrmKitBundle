<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Symfony\Component\DomCrawler\Crawler;

use function array_slice;
use function is_array;
use function str_contains;
use function strlen;

/**
 * The HubSpot pages (0.10.0): the source link of the import, the connection card, connect / replace / disconnect,
 * start and mapping, as pages and as step fragments of the import modal; every denial answers 404; the credential is
 * never re-rendered.
 */
final class HubSpotUiTest extends HubSpotTestCase
{
    /** Server variables of the modal's calls. */
    private const DIALOG = ['HTTP_X_NOWO_CRM_DIALOG' => 'import', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html'];

    public function testEveryDenialAnswersNotFound(): void
    {
        $fake = $this->hubspot();
        $this->connect($fake);
        $id     = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Company]));
        $routes = ['GET /crm/import/hubspot', 'GET /crm/import/hubspot/connect', 'POST /crm/import/hubspot/connect', 'POST /crm/import/hubspot/disconnect', 'GET /crm/import/hubspot/new', 'GET /crm/import/hubspot/' . $id . '/mapping'];
        foreach ([[], ['view'], ['view', 'manage']] as $grants) {
            $this->ctx()->grants = [];
            $this->actAs('ana', ...$grants);
            foreach ($routes as $route) {
                [$method, $path] = explode(' ', $route);
                self::assertSame(404, $this->httpStatus($method, $path), $route . ' with ' . implode(',', $grants));
            }
        }
        // import without manage: the card and the steps, not connect / disconnect.
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        self::assertSame(200, $this->httpStatus('GET', '/crm/import/hubspot'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/import/hubspot/connect'));
        self::assertSame(404, $this->httpStatus('POST', '/crm/import/hubspot/disconnect'));
        // Another tenant, an unknown import.
        self::assertSame(404, $this->httpStatus('GET', '/crm/import/hubspot/01890000-0000-7000-8000-000000000000/mapping'));
        $this->ctx()->tenant = 'globex';
        $this->actAs('ana', 'view', 'edit', 'manage');
        self::assertSame(404, $this->httpStatus('GET', '/crm/import/hubspot/' . $id . '/mapping'));
        $this->ctx()->tenant = null;
        self::assertSame(404, $this->httpStatus('GET', '/crm/import/hubspot'));
    }

    public function testConnectReplaceAndDisconnectAsPages(): void
    {
        $fake = $this->hubspot();
        $card = $this->client->request('GET', '/crm/import/hubspot');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $card->filter('[data-testid="crm-hubspot-connect"]'));
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        self::assertCount(1, $this->client->request('GET', '/crm/import/hubspot')->filter('[data-testid="crm-hubspot-ask-manager"]'));
        $this->admin();
        $page = $this->client->request('GET', '/crm/import/hubspot/connect');
        self::assertSame('password', $page->filter('input[name="crm_hubspot_connect[token]"]')->attr('type'));
        $form = $page->filter('form#crm-hubspot-form')->form();
        $this->client->submit($form, ['crm_hubspot_connect[token]' => 'wrong-key-1234567']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertFalse(str_contains($html, 'wrong-key-1234567'), 'the credential is never re-rendered');
        self::assertStringContainsString('HubSpot refused', $html);
        $form = $this->client->getCrawler()->filter('form#crm-hubspot-form')->form();
        $this->client->submit($form, ['crm_hubspot_connect[token]' => $fake->token, 'crm_hubspot_connect[label]' => 'Sales portal']);
        self::assertTrue($this->client->getResponse()->isRedirect('/crm/import/hubspot'));
        $card = $this->client->followRedirect();
        self::assertSame('••••' . substr($fake->token, -4), trim($card->filter('[data-testid="crm-hubspot-last4"]')->text()));
        self::assertFalse(str_contains((string) $this->client->getResponse()->getContent(), $fake->token));
        self::assertStringContainsString('Sales portal', (string) $this->client->getResponse()->getContent());
        // Replace with a stale version: 409 in place.
        $page = $this->client->request('GET', '/crm/import/hubspot/connect');
        $form = $page->filter('form#crm-hubspot-form')->form();
        $this->connections()->connect($fake->token, null, 1);
        $this->client->submit($form, ['crm_hubspot_connect[token]' => $fake->token]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        // Disconnect with the version shown; a stale one answers 409 with the card.
        $card = $this->client->request('GET', '/crm/import/hubspot');
        $form = $card->filter('[data-testid="crm-hubspot-disconnect"]')->form();
        $this->client->submit($form, ['crm_action[version]' => '99']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->request('GET', '/crm/import/hubspot')->filter('[data-testid="crm-hubspot-disconnect"]')->form());
        self::assertTrue($this->client->getResponse()->isRedirect('/crm/import/hubspot'));
        self::assertNull($this->connections()->status());
        $this->client->request('POST', '/crm/import/hubspot/disconnect', ['crm_action' => ['version' => '1']]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'no CSRF token');
    }

    public function testTheModalFlowFromTheSourceLinkToTheResult(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(3);
        $fake->lists = ['77' => ['name' => 'Fans <script>', 'type' => '0-1', 'members' => ['2001']]];
        $file        = $this->dialog('GET', '/crm/import?subject=contact');
        self::assertSame('/crm/import/hubspot', $file->filter('[data-testid="crm-import-source-hubspot"] a[data-crm-import-link]')->attr('href'));
        $upload = $this->client->request('GET', '/crm/import');
        self::assertCount(1, $upload->filter('[data-testid="crm-import-source-hubspot"] a'));
        $source = $this->dialog('GET', '/crm/import/hubspot');
        self::assertSame('hubspot-source', $source->filter('[data-crm-import-step]')->attr('data-step'));
        $connect = $this->dialog('GET', '/crm/import/hubspot/connect');
        $form    = $connect->filter('form#crm-import-dialog-form')->form(['crm_hubspot_connect[token]' => $fake->token]);
        $this->client->request('POST', '/crm/import/hubspot/connect', $form->getPhpValues(), [], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('/crm/import/hubspot', $this->client->getResponse()->headers->get('X-Nowo-Crm-Dialog-Location'));
        $start = $this->dialog('GET', '/crm/import/hubspot/new');
        self::assertStringContainsString('Fans &lt;script&gt;', (string) $this->client->getResponse()->getContent(), 'list names are escaped');
        $form   = $start->filter('form#crm-import-dialog-form')->form();
        $php    = $form->getPhpValues();
        $fields = is_array($php['crm_hubspot_start'] ?? null) ? $php['crm_hubspot_start'] : [];
        $this->client->request('POST', '/crm/import/hubspot/new', ['crm_hubspot_start' => ['objects' => []] + $fields], [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'at least one object');
        $values = ['crm_hubspot_start' => ['objects' => ['contacts'], 'lists' => ['77']] + $fields];
        $this->client->request('POST', '/crm/import/hubspot/new', $values, [], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $next = (string) $this->client->getResponse()->headers->get('X-Nowo-Crm-Dialog-Location');
        self::assertMatchesRegularExpression('#\A/crm/import/hubspot/[0-9a-f-]{36}/mapping\z#', $next);
        // A list that vanished meanwhile: 422 in place.
        $fake->lists = ['78' => ['name' => 'Other', 'type' => '0-1', 'members' => []]];
        $this->client->request('POST', '/crm/import/hubspot/new', $values, [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $fake->lists = ['77' => ['name' => 'Fans <script>', 'type' => '0-1', 'members' => ['2001']]];
        // A second start while one is open: 409 in place.
        $this->client->request('POST', '/crm/import/hubspot/new', $values, [], self::DIALOG);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->service(TagServiceInterface::class)->create('Existing', 'red');
        $mapping = $this->dialog('GET', $next);
        self::assertCount(1, $mapping->filter('option:contains("Existing")'));
        self::assertStringContainsString('Phone &lt;b&gt;', (string) $this->client->getResponse()->getContent());
        // The import page of a HubSpot import in preview sends to the mapping step.
        $id = substr($next, strlen('/crm/import/hubspot/'), 36);
        $this->client->request('GET', '/crm/import/' . $id, [], [], self::DIALOG);
        self::assertSame($next, $this->client->getResponse()->headers->get('X-Nowo-Crm-Dialog-Location'));
        $this->client->request('GET', '/crm/import/' . $id);
        self::assertTrue($this->client->getResponse()->isRedirect($next));
        $form   = $mapping->filter('form#crm-import-dialog-form')->form();
        $values = $form->getPhpValues();
        $this->client->request('POST', $next, $values, [], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('/crm/import/' . $id, $this->client->getResponse()->headers->get('X-Nowo-Crm-Dialog-Location'));
        $result = $this->dialog('GET', '/crm/import/' . $id);
        self::assertSame('result', $result->filter('[data-crm-import-step]')->attr('data-step'));
        while ($this->imports()->run($id, 5) === false) {
            // finish
        }
        $page = $this->client->request('GET', '/crm/import/' . $id);
        self::assertSame(['object', 'id'], array_slice($page->filter('[data-testid="crm-import-rows"] thead th')->each(static fn (Crawler $th): string => $th->text() === 'Object' ? 'object' : ($th->text() === 'HubSpot id' ? 'id' : '')), 1, 2));
        // Confirmed: the mapping step sends to the import page.
        $this->client->request('GET', $next);
        self::assertTrue($this->client->getResponse()->isRedirect('/crm/import/' . $id));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_tag WHERE name = 'Fans <script>'"));
    }

    public function testErrorsInPlace(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(2);
        // Not connected: the start step says so.
        $start = $this->client->request('GET', '/crm/import/hubspot/new');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('HubSpot is not connected', $start->filter('[data-testid="crm-hubspot-alert"]')->text());
        $form = $start->filter('form#crm-hubspot-form')->form();
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->connect($fake);
        $fake->failNext('/crm/v3/lists/search', 503);
        $this->client->request('GET', '/crm/import/hubspot/new');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $id = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact]));
        $fake->failNext('/crm/v3/properties/companies', 503);
        $this->client->request('GET', '/crm/import/hubspot/' . $id . '/mapping');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/crm/import/hubspot/' . $id . '/mapping');
        $form = $page->filter('form#crm-hubspot-form')->form();
        // HubSpot no longer offers a mapped property when the confirm asks again: 422 in place.
        $calls           = 0;
        $fake->onRequest = static function (string $path) use ($fake, &$calls): void {
            if ($path === '/crm/v3/properties/contacts' && ++$calls === 2) {
                $fake->properties['contacts'] = [];
            }
        };
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $fake->onRequest  = null;
        $fake->properties = (new FakeHubSpot())->properties;
        // A stale version: 409 in place.
        $page = $this->client->request('GET', '/crm/import/hubspot/' . $id . '/mapping');
        $form = $page->filter('form#crm-hubspot-form')->form();
        $this->client->submit($form, ['crm_hubspot_mapping[version]' => '99']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('form#crm-hubspot-form')->form();
        // The policy "update" needs edit, so does any mapping that no longer exists at HubSpot.
        $fake->properties['contacts'] = [];
        $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->imports()->cancel($id);
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect('/crm/import/' . $id), 'no longer in preview: the import page');
        $this->client->request('POST', '/crm/import/hubspot/connect', ['crm_hubspot_connect' => ['token' => 'x']]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    private function dialog(string $method, string $path): Crawler
    {
        $crawler = $this->client->request($method, $path, [], [], self::DIALOG);
        self::assertSame('import-step', $this->client->getResponse()->headers->get('X-Nowo-Crm-Fragment'), $path . ' ' . $this->client->getResponse()->getStatusCode());

        return $crawler;
    }
}
