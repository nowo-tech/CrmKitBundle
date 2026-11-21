<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ImportDialog;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

use function file_put_contents;
use function is_array;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;

/**
 * 0.4.0: the import modal of the lists (ImportDialog). The list exposes the page action "Import" and the modal only
 * to someone who may import; the import routes answer the modal's calls with step fragments (file ⇒ mapping ⇒
 * preview ⇒ result), 204 + the next step's address instead of a redirect, errors in place; the same access checks and
 * CSRF tokens as the pages; an expired session answers 401 `session_expired`; lock conflicts keep REQ-RUNTIME-003
 * rule 6. The page stays the no-JS fallback (UiTest).
 */
final class ImportDialogTest extends CrmTestCase
{
    /** Server variables of the modal's calls. */
    private const DIALOG = ['HTTP_X_NOWO_CRM_DIALOG' => 'import', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html'];

    public function testTheListExposesTheImportActionAndTheModalOnlyToImporters(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/contacts');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $open = $page->filter('[data-testid="crm-import-open"]');
        self::assertCount(1, $open);
        self::assertSame('/crm/import?subject=contact', $open->attr('href'), 'the no-JS fallback: the import page');
        self::assertSame('dialog', $open->attr('aria-haspopup'));
        $dialog = $page->filter('dialog[data-crm-import-dialog]');
        self::assertCount(1, $dialog);
        self::assertSame('/crm/import?subject=contact', $dialog->attr('data-crm-import-dialog'));
        foreach (['nowo-ui-confirm-dialog--anatomy', 'nowo-ui-confirm-dialog--wide'] as $class) {
            self::assertStringContainsString($class, (string) $dialog->attr('class'));
        }
        self::assertSame('crm-import-dialog-title', $dialog->attr('aria-labelledby'));
        self::assertCount(1, $dialog->filter('.nowo-ui-dialog__header #crm-import-dialog-title'));
        self::assertCount(1, $dialog->filter('.nowo-ui-dialog__footer [data-crm-import-close]'));
        self::assertNotSame('', (string) $dialog->attr('data-expired'));
        self::assertCount(1, $page->filter('script[src="/bundles/nowocrmkit/js/nowo-crm-import.js"]'));
        self::assertCount(0, $page->filter('style'), 'no inline style');
        self::assertCount(0, $page->filter('a[href="/crm/import"]'), 'no area tab to the import page any more');
        self::assertSame('/crm/import?subject=company', $this->client->request('GET', '/crm/companies')->filter('[data-testid="crm-import-open"]')->attr('href'));

        $this->actAs('zoe', 'view');
        $page = $this->client->request('GET', '/crm/contacts');
        self::assertCount(0, $page->filter('[data-testid="crm-import-open"]'));
        self::assertCount(0, $page->filter('dialog[data-crm-import-dialog]'));
        self::assertCount(0, $page->filter('script[src$="nowo-crm-import.js"]'));
    }

    public function testTheStepsOfTheModal(): void
    {
        $this->boot();
        $this->admin();
        $this->contact('Ada', 'Lovelace', 'ada@example.test');

        // Step 1: the file, with the list's subject chosen.
        $file = $this->dialog('GET', '/crm/import?subject=contact');
        $this->assertStep($file, 'file', Response::HTTP_OK);
        self::assertSame('contact', $file->filter('select[name="crm_import[subject]"] option[selected]')->attr('value'));
        self::assertCount(1, $file->filter('[data-part="body"] form#crm-import-dialog-form[enctype="multipart/form-data"]'));
        self::assertCount(0, $file->filter('[data-part="body"] .nowo-ui-card'), 'no cards inside the modal');
        self::assertCount(1, $file->filter('[data-part="body"] .nowo-ui-steps [aria-current="step"]'));
        $footer = $file->filter('[data-part="footer"] button');
        self::assertSame('crm-import-dialog-cancel', $footer->eq(0)->attr('data-testid'), 'Cancel first');
        self::assertCount(1, $file->filter('[data-part="footer"] .nowo-ui-btn--primary'), 'one primary');
        self::assertSame('crm-import-dialog-form', $footer->eq(1)->attr('form'));
        $form = $file->filter('#crm-import-dialog-form')->form();

        // An invalid file: 422, the error in place.
        $this->dialog('POST', $form->getUri(), $form->getPhpValues(), ['crm_import' => ['file' => $this->file("name\n")]]);
        $this->assertStep(new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost'), 'file', Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('no rows', (string) $this->client->getResponse()->getContent());

        // A valid file: 204 + the next step's address.
        $this->dialog('POST', $form->getUri(), $form->getPhpValues(), ['crm_import' => ['file' => $this->file("first_name,last_name,email,notes\nGrace,Hopper,grace@example.test,x\nAda,L,ada@example.test,y\n")]]);
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        $next = (string) $response->headers->get(ImportDialog::LOCATION_HEADER);
        self::assertMatchesRegularExpression('#^/crm/import/[0-9a-f-]{36}$#', $next);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Step 2: the mapping (the detected one), Cancel then "Preview".
        $mapping = $this->dialog('GET', $next);
        $this->assertStep($mapping, 'mapping', Response::HTTP_OK);
        self::assertSame('preview', $mapping->filter('[data-part="footer"] .nowo-ui-btn--primary')->attr('value'));
        $values = $mapping->filter('#crm-import-dialog-form')->form()->getPhpValues();

        // Step 3: the preview, checked against the chosen mapping and stored nowhere.
        $this->dialog('POST', $next, $this->confirm($values, ['col_3' => 'skip-me']) + ['do' => 'preview']);
        $invalid = new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost');
        $this->assertStep($invalid, 'mapping', Response::HTTP_UNPROCESSABLE_ENTITY);
        $values  = $this->confirm($values, ['col_2' => '', 'col_3' => '']);
        $preview = $this->dialog('POST', $next, $values + ['do' => 'preview']);
        $this->assertStep($preview, 'preview', Response::HTTP_OK);
        self::assertCount(2, $preview->filter('[data-testid="crm-import-row"]'));
        self::assertStringNotContainsString('Same e-mail', $preview->filter('[data-testid="crm-import-preview"]')->text(), 'no e-mail column mapped: no duplicate');
        self::assertSame('confirm', $preview->filter('[data-part="footer"] .nowo-ui-btn--primary')->attr('value'));
        self::assertCount(1, $preview->filter('details[data-testid="crm-import-mapping-fold"]'));
        self::assertCount(0, $preview->filter('details[open]'));
        $stored = $this->service(ImportServiceInterface::class)->preview(basename($next));
        self::assertSame('email', $stored->mapping['email'] ?? null, 'the preview stored nothing');

        // A mapping refused by the domain (a field twice): the input error in place.
        $this->dialog('POST', $next, $this->confirm($values, ['col_1' => 'first_name']) + ['do' => 'preview']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        // Without a CSRF token: 422, nothing confirmed.
        $this->dialog('POST', $next, $this->confirm($values, ['_token' => null]) + ['do' => 'confirm']);
        $this->assertStep(new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost'), 'preview', Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('preview', $this->service(ImportServiceInterface::class)->preview(basename($next))->status->value);

        // Step 4: "Import" confirms and runs; 204 to the result (Close only, finished).
        $this->dialog('POST', $next, $values + ['do' => 'confirm']);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $result = $this->dialog('GET', (string) $this->client->getResponse()->headers->get(ImportDialog::LOCATION_HEADER));
        $this->assertStep($result, 'result', Response::HTTP_OK);
        self::assertSame('done', $result->filter('[data-crm-import-step]')->attr('data-status'));
        self::assertNotNull($result->filter('[data-crm-import-step]')->attr('data-crm-import-finished'));
        self::assertCount(0, $result->filter('[data-part="footer"] .nowo-ui-btn--primary'), 'a result: Close only');
        self::assertCount(0, $result->filter('[data-testid="crm-import-open-page"]'), 'a finished import needs no link');
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'), 'both rows created (no e-mail column mapped)');

        // A second confirm: the domain conflict in place (409, the result as it is now).
        $this->dialog('POST', $next, $values + ['do' => 'confirm']);
        $conflict = new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost');
        $this->assertStep($conflict, 'result', Response::HTTP_CONFLICT);
        self::assertCount(1, $conflict->filter('[data-testid="crm-import-alert"][role="alert"]'));

        // The import page still works (no-JS fallback) and says it depends on the modal header.
        $this->client->request('GET', $next);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->client->getResponse()->headers->get(ImportDialog::FRAGMENT_HEADER));
        self::assertStringContainsString(ImportDialog::REQUEST_HEADER, (string) $this->client->getResponse()->headers->get('Vary'));
    }

    public function testARunningImportContinuesAndCancelsInTheModal(): void
    {
        $this->boot(['import' => ['sync_rows' => 10, 'chunk_rows' => 10]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'big.csv', "name\n" . $this->rows(40));
        $imports->confirm($id, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($id, 1);

        $result = $this->dialog('GET', '/crm/import/' . $id);
        $this->assertStep($result, 'result', Response::HTTP_OK);
        self::assertSame('running', $result->filter('[data-crm-import-step]')->attr('data-status'));
        self::assertSame('run', $result->filter('[data-part="footer"] .nowo-ui-btn--primary')->attr('value'), 'Continue is the primary');
        self::assertCount(1, $result->filter('[data-part="body"] button[value="cancel"].nowo-ui-btn--secondary'));
        // Audit XU-M1: the import page stays reachable from the modal.
        self::assertSame('/crm/import/' . $id, $result->filter('[data-testid="crm-import-open-page"]')->attr('href'));
        self::assertSame('/crm/import/' . $id, $result->filter('[data-crm-import-step]')->attr('data-crm-import-page'));
        $values = $result->filter('#crm-import-dialog-form')->form()->getPhpValues();

        // Continue without the CSRF token: 422, the error in place.
        $this->dialog('POST', '/crm/import/' . $id, ['do' => 'run']);
        $refused = new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost');
        $this->assertStep($refused, 'result', Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(1, $refused->filter('[data-testid="crm-import-alert"]'));

        $this->dialog('POST', '/crm/import/' . $id, $values + ['do' => 'run']);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        // A fresh claim cannot be cancelled: the domain conflict in place (409, the result as it is now).
        $busy = $this->dialog('POST', '/crm/import/' . $id, $values + ['do' => 'cancel']);
        $this->assertStep($busy, 'result', Response::HTTP_CONFLICT);
        self::assertCount(1, $busy->filter('[data-testid="crm-import-alert"]'));
        // A stale claim can.
        $this->clock()->sleep(3600);
        $this->dialog('POST', '/crm/import/' . $id, $values + ['do' => 'cancel']);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $cancelled = $this->dialog('GET', '/crm/import/' . $id);
        self::assertSame('cancelled', $cancelled->filter('[data-crm-import-step]')->attr('data-status'));
    }

    public function testALockConflictKeepsTheCodesOfRule6(): void
    {
        $this->boot(['import' => ['sync_rows' => 10, 'chunk_rows' => 10]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'big.csv', "name\n" . $this->rows(40));
        $imports->confirm($id, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($id, 1);
        $values = $this->dialog('GET', '/crm/import/' . $id)->filter('#crm-import-dialog-form')->form()->getPhpValues();

        // Continue: nothing committed before the chunk that keeps deadlocking ⇒ CONFLICT_001 + Retry-After.
        $this->ctx()->races['Deadlock'] = 1000;
        $this->dialog('POST', '/crm/import/' . $id, $values + ['do' => 'run']);
        $response = $this->client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertNotNull($response->headers->get('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_001', $body['code'] ?? null);

        // "Import": the claim committed, then the run's chunk deadlocks ⇒ CONFLICT_002, no Retry-After.
        $other   = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'b.csv', "name\n" . $this->rows(20));
        $confirm = $this->dialog('GET', '/crm/import/' . $other)->filter('#crm-import-dialog-form')->form()->getPhpValues();
        $this->dialog('POST', '/crm/import/' . $other, $confirm + ['do' => 'confirm']);
        $response = $this->client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertNull($response->headers->get('Retry-After'));
        $this->ctx()->races['Deadlock'] = 0;
    }

    public function testAContinueOfSeveralChunksWhoseSecondDeadlocksAnswersConflict002(): void
    {
        // Audit XU-L2: "Continue" runs two chunks; the first commits, the second keeps deadlocking.
        $this->boot(['import' => ['sync_rows' => 20, 'chunk_rows' => 10]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'big.csv', "name\n" . $this->rows(50));
        $imports->confirm($id, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($id, 1);
        self::assertSame(10, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));
        $values = $this->dialog('GET', '/crm/import/' . $id)->filter('#crm-import-dialog-form')->form()->getPhpValues();

        $this->ctx()->races['DeadlockSkip'] = 10;
        $this->ctx()->races['Deadlock']     = 1000;
        $this->dialog('POST', '/crm/import/' . $id, $values + ['do' => 'run']);
        $response = $this->client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertNull($response->headers->get('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_002', $body['code'] ?? null);
        self::assertSame(20, $this->int('SELECT COUNT(*) FROM nowo_crm_company'), 'the first chunk stays committed');
        $this->ctx()->races['Deadlock'] = 0;

        // The import is still running and reachable: from its page, and from the list's notice.
        $view = $imports->preview($id);
        self::assertSame('running', $view->status->value);
        self::assertSame(20, $view->rowsDone);
    }

    public function testTheListLinksThePersonsUnfinishedImports(): void
    {
        // Audit XU-M1: an import left running (after sync_rows, waiting for a person, or after a conflict) stays
        // reachable once the modal is closed.
        $this->boot(['import' => ['sync_rows' => 10, 'chunk_rows' => 10]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $running = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'running.csv', "name\n" . $this->rows(30));
        $imports->confirm($running, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($running, 1);
        $done = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'done.csv', "name\nSolo\n");
        $imports->confirm($done, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($done);
        $imports->upload(SubjectType::Company, ImportFormat::Csv, 'preview.csv', "name\nLater\n");

        $page   = $this->client->request('GET', '/crm/contacts');
        $notice = $page->filter('[data-testid="crm-import-unfinished"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('nowo-ui-flash--info', (string) $notice->attr('class'));
        self::assertStringContainsString('one unfinished import', $notice->text());
        self::assertSame(['/crm/import/' . $running], $notice->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame('running.csv', $notice->filter('a')->text());
        $this->client->click($notice->filter('a')->link());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('running', $this->client->getCrawler()->filter('[data-testid="crm-import-summary"]')->attr('data-status'));

        $second = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'second.csv', "name\n" . $this->rows(30));
        $imports->confirm($second, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($second, 1);
        self::assertStringContainsString('2 unfinished imports', $this->client->request('GET', '/crm/companies')->filter('[data-testid="crm-import-unfinished"]')->text());

        // Another importer of the tenant: none of theirs. Someone who may not import: no notice.
        $this->actAs('leo', 'view', 'edit');
        self::assertCount(0, $this->client->request('GET', '/crm/contacts')->filter('[data-testid="crm-import-unfinished"]'));
        $this->actAs('zoe', 'view');
        self::assertCount(0, $this->client->request('GET', '/crm/contacts')->filter('[data-testid="crm-import-unfinished"]'));
        // Another tenant: none.
        $this->ctx()->tenant = 'beta';
        $this->ctx()->grant('beta', 'ana', 'view', 'edit');
        $this->ctx()->actor = 'ana';
        self::assertCount(0, $this->client->request('GET', '/crm/contacts')->filter('[data-testid="crm-import-unfinished"]'));
        self::assertSame(['total' => 0, 'imports' => []], $imports->unfinished());
        $this->ctx()->tenant = 'acme';
        self::assertSame(2, $imports->unfinished(1)['total']);
        self::assertCount(1, $imports->unfinished(1)['imports']);
    }

    public function testAccessAndAnExpiredSession(): void
    {
        $this->boot();
        $this->admin();
        $id = $this->service(ImportServiceInterface::class)->upload(SubjectType::Company, ImportFormat::Csv, 'a.csv', "name\nAcme\n");

        // The same access checks as the pages: no import permission ⇒ 403; another tenant ⇒ 404.
        $this->actAs('zoe', 'view');
        $this->dialog('GET', '/crm/import');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->dialog('GET', '/crm/import/' . $id);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->ctx()->tenant = 'beta';
        $this->ctx()->grant('beta', 'zoe', 'view', 'edit');
        $this->dialog('GET', '/crm/import/' . $id);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->ctx()->tenant = 'acme';

        // Nobody signed in: the modal gets 401 session_expired (its reload notice), the page the firewall's entry point.
        $this->ctx()->actor = null;
        $this->dialog('GET', '/crm/import');
        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'session_expired', 'reload' => true], json_decode((string) $response->getContent(), true));
        self::assertNull($response->headers->get(ImportDialog::FRAGMENT_HEADER));
        $this->dialog('POST', '/crm/import/' . $id, ['do' => 'confirm']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame(401, $this->httpStatus('GET', '/crm/import'), 'the page: the firewall entry point (HTTP Basic)');
        self::assertNotNull($this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testPreviewMappingRefusesAnInvalidMapping(): void
    {
        $this->boot();
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'a.csv', "name,city\nAcme,Madrid\n");
        self::assertSame(['name' => '', 'city' => 'city'], $imports->previewMapping($id, ['name' => '', 'city' => 'city'])->mapping);
        $this->expectException(InvalidInputException::class);
        $imports->previewMapping($id, ['nope' => 'name']);
    }

    /**
     * A call of the modal; the fragment.
     *
     * @param array<mixed> $parameters
     * @param array<mixed> $files
     */
    private function dialog(string $method, string $uri, array $parameters = [], array $files = []): Crawler
    {
        $this->client->request($method, $uri, $parameters, $files, self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost' . $uri);
    }

    private function assertStep(Crawler $fragment, string $step, int $status): void
    {
        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(ImportDialog::FRAGMENT_VALUE, $response->headers->get(ImportDialog::FRAGMENT_HEADER));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($step, $fragment->filter('[data-crm-import-step]')->attr('data-crm-import-step'));
        foreach (['<html', '<body', '<script', '<style'] as $tag) {
            self::assertStringNotContainsString($tag, (string) $response->getContent(), 'a fragment, not a page');
        }
        self::assertCount(1, $fragment->filter('[data-part="body"]'));
        self::assertCount(1, $fragment->filter('[data-part="footer"]'));
        self::assertLessThanOrEqual(1, $fragment->filter('[data-part="footer"] .nowo-ui-btn--primary')->count());
    }

    /**
     * The confirm form's values with some fields changed (null removes one).
     *
     * @param array<mixed> $values
     * @param array<string, string|null> $changes
     *
     * @return array<mixed>
     */
    private function confirm(array $values, array $changes): array
    {
        $fields = is_array($values['crm_import_confirm'] ?? null) ? $values['crm_import_confirm'] : [];
        foreach ($changes as $name => $value) {
            if ($value === null) {
                unset($fields[$name]);
            } else {
                $fields[$name] = $value;
            }
        }

        return ['crm_import_confirm' => $fields] + $values;
    }

    private function rows(int $count): string
    {
        $rows = '';
        for ($i = 0; $i < $count; ++$i) {
            $rows .= 'Company ' . $i . "\n";
        }

        return $rows;
    }

    private function file(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crm');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }
}
