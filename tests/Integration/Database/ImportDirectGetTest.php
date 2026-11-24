<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ImportDialogPageResolver;

use function parse_str;
use function parse_url;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * 0.10.3 (Beacon D-20261124-05, `import.dialog.direct_get: redirect`, UiKit 2.74 `remote_dialog.direct_get`): a step
 * of the import modal opened directly (address bar, bookmark, another site) lands on its list with the modal reopened
 * on that step; the modal's own fetches, links of the app and POSTs are unchanged; a denied person keeps the 403 / 404;
 * the list reopens only the kit's own step routes.
 */
final class ImportDirectGetTest extends CrmTestCase
{
    /** A top-level navigation from the address bar (fetch metadata of a browser). */
    public const NAVIGATE = ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_SITE' => 'none'];

    /** The modal's own request of a step. */
    public const DIALOG = ['HTTP_X_NOWO_CRM_DIALOG' => 'import', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html', 'HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function testTheFileStepOpenedDirectlyReopensOnItsList(): void
    {
        $this->boot(['import' => ['dialog' => ['direct_get' => 'redirect']]]);
        $this->admin();

        $this->client->request('GET', '/crm/import?subject=contact', [], [], self::NAVIGATE);
        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertSame('/crm/contacts', parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $reopen = $query['_dialog'] ?? null;
        self::assertIsArray($reopen);
        self::assertSame(ImportDialogPageResolver::FILE_ROUTE, $reopen['route'] ?? null);
        self::assertIsArray($reopen['params'] ?? null);
        self::assertSame('contact', $reopen['params']['subject'] ?? null);

        // the list prints the reopen contract on the import modal: the safe list and the URL the router made again
        $page   = $this->client->request('GET', $location);
        $dialog = $page->filter('dialog[data-crm-import-dialog]');
        self::assertCount(1, $dialog);
        self::assertSame('_dialog', $dialog->attr('data-nowo-ui-remote-form-reopen-param'));
        self::assertStringContainsString(ImportDialogPageResolver::IMPORT_ROUTE, (string) $dialog->attr('data-nowo-ui-remote-form-reopen-routes'));
        self::assertSame(ImportDialogPageResolver::FILE_ROUTE, $dialog->attr('data-nowo-ui-remote-form-reopen-route'));
        self::assertSame('/crm/import?subject=contact', $dialog->attr('data-nowo-ui-remote-form-reopen-url'));

        // a company file step (or none) lands on the companies list
        $this->client->request('GET', '/crm/import', [], [], self::NAVIGATE);
        self::assertSame('/crm/companies', parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    public function testTheStepsOfAnImportReopenOnTheListOfItsSubject(): void
    {
        $this->boot(['import' => ['dialog' => ['direct_get' => 'redirect']]]);
        $this->admin();
        $id = $this->service(ImportServiceInterface::class)->upload(SubjectType::Contact, ImportFormat::Csv, 'people.csv', "email,last_name\na@x.test,A\n");

        $this->client->request('GET', '/crm/import/' . $id, [], [], self::NAVIGATE);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('/crm/contacts', parse_url($location, PHP_URL_PATH));
        $page = $this->client->request('GET', $location);
        self::assertSame('/crm/import/' . $id, $page->filter('dialog[data-crm-import-dialog]')->attr('data-nowo-ui-remote-form-reopen-url'));

        // the modal's own fetch gets the step fragment, a link of the app followed without JavaScript keeps the page
        $this->client->request('GET', '/crm/import/' . $id, [], [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('import-step', $this->client->getResponse()->headers->get('X-Nowo-Crm-Fragment'));
        $this->client->request('GET', '/crm/import/' . $id, [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin'] + self::NAVIGATE);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the no-JS page of a link of the app');
        // no fetch metadata (a script, an old browser): nothing proves a navigation, the page stays
        $this->client->request('GET', '/crm/import/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testADeniedPersonKeepsTheRefusalNeverTheRedirect(): void
    {
        $this->boot(['import' => ['dialog' => ['direct_get' => 'redirect']]]);
        $this->admin();
        $id = $this->service(ImportServiceInterface::class)->upload(SubjectType::Company, ImportFormat::Csv, 'acme.csv', "name\nAcme\n");

        $this->actAs('leo', 'view');
        foreach (['/crm/import', '/crm/import/' . $id] as $path) {
            $this->client->request('GET', $path, [], [], self::NAVIGATE);
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], $path);
            self::assertNull($this->client->getResponse()->headers->get('Location'), $path);
        }
        // an import of another person or an unknown id: no redirect either
        $this->client->request('GET', '/crm/import/00000000-0000-7000-8000-000000000000', [], [], self::NAVIGATE);
        self::assertNull($this->client->getResponse()->headers->get('Location'));
    }

    public function testTheListReopensOnlyTheKitsOwnStepRoutes(): void
    {
        $this->boot(['import' => ['dialog' => ['direct_get' => 'redirect']]]);
        $this->admin();
        foreach (['?_dialog[route]=nowo_crm_kit_settings', '?_dialog[route]=nowo_crm_kit_import_show&_dialog[params][id]=..%2F..%2Fx', '?_dialog=https://evil.test/'] as $query) {
            $dialog = $this->client->request('GET', '/crm/companies' . $query)->filter('dialog[data-crm-import-dialog]');
            self::assertCount(1, $dialog, $query);
            self::assertNull($dialog->attr('data-nowo-ui-remote-form-reopen-url'), $query);
            self::assertNull($dialog->attr('data-nowo-ui-remote-form-reopen-route'), $query);
        }
    }

    public function testOffByDefault(): void
    {
        $this->boot();
        $this->admin();
        $this->client->request('GET', '/crm/import?subject=contact', [], [], self::NAVIGATE);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->client->request('GET', '/crm/contacts')->filter('dialog[data-crm-import-dialog]')->attr('data-nowo-ui-remote-form-reopen-param'));
    }
}
