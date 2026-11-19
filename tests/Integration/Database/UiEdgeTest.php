<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use LogicException;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The rarer page paths: contacts lists, custom fields of every type in forms, owners that left, plan limit and stale
 * merges on the pages, invalid host ids, empty posts, contact imports with custom targets, nested transactions.
 */
final class UiEdgeTest extends CrmTestCase
{
    public function testContactFormsWithEveryCustomType(): void
    {
        $this->boot();
        $this->admin();
        $fields = $this->service(FieldServiceInterface::class);
        $fields->define(SubjectType::Contact, 'site', 'Site', FieldType::Url, [], false, 1);
        $fields->define(SubjectType::Contact, 'mail', 'Mail', FieldType::Email, [], false, 2);
        $fields->define(SubjectType::Contact, 'born', 'Born', FieldType::Date, [], false, 3);
        $fields->define(SubjectType::Contact, 'size', 'Size', FieldType::Number, [], false, 4);
        $fields->define(SubjectType::Contact, 'bio', 'Bio', FieldType::Textarea, [], false, 5);
        $fields->define(SubjectType::Contact, 'nick', 'Nick', FieldType::Text, [], false, 6);
        $page = $this->client->request('GET', '/crm/contacts/new');
        $this->client->submit($page->filter('[data-testid="crm-record-form"] form')->form(), ['crm_record[last_name]' => 'Lovelace', 'crm_record[custom_site]' => 'https://ada.test', 'crm_record[custom_mail]' => 'a@ada.test', 'crm_record[custom_born]' => '1815-12-10', 'crm_record[custom_size]' => '38', 'crm_record[custom_bio]' => "One\nTwo", 'crm_record[custom_nick]' => 'Ada']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/crm/contacts');
        self::assertStringContainsString('Lovelace', (string) $this->client->getResponse()->getContent());
        $leo = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Owned'], 'leo'));
        unset($this->ctx()->owners['acme']['leo']);
        $edit = $this->client->request('GET', '/crm/contacts/' . $leo . '/edit');
        self::assertStringContainsString('leo', $edit->filter('select[name="crm_record[owner]"]')->text(), 'a former owner stays selectable');
    }

    public function testThePlanLimitAndStaleMergesOnThePages(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->limits['companies'] = 2;
        $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'tier', 'Tier', FieldType::Text, [], false, 1);
        $gone = $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'old', 'Old', FieldType::Text, [], false, 2);
        $a    = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Acme'], null, ['tier' => 'A']));
        $b    = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'ACME'], null, ['tier' => 'B', 'old' => 'x']));
        $this->service(FieldServiceInterface::class)->archive($gone, true, 1);
        $page = $this->client->request('GET', '/crm/companies/new');
        $this->client->submit($page->filter('[data-testid="crm-record-form"] form')->form(), ['crm_record[name]' => 'Third']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('plan limit', (string) $this->client->getResponse()->getContent());

        $preview = $this->client->request('GET', '/crm/companies/' . $a . '/merge?other=' . $b);
        self::assertStringContainsString('Tier', $preview->filter('[data-testid="crm-merge-preview"]')->text());
        self::assertStringContainsString('old', $preview->filter('[data-testid="crm-merge-preview"]')->text(), 'an archived field shows its key');
        $this->records()->update($b, new RecordInput(SubjectType::Company, ['city' => 'Madrid']), 1);
        $this->client->submit($preview->filter('form[name="crm_merge"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('changed or was merged', $this->client->followRedirect()->html());
        $this->records()->trash(SubjectType::Company, $b, 2);
        self::assertSame(303, $this->httpStatus('GET', '/crm/companies/' . $b . '/merge'), 'a trashed record is not merged');
    }

    public function testInvalidHostIdsEmptyPostsAndContactImports(): void
    {
        $this->boot();
        $this->admin();
        $this->service(FieldServiceInterface::class)->define(SubjectType::Contact, 'vip', 'VIP', FieldType::Boolean, [], false, 1);
        $acme = $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies/' . $acme);
        $this->client->submit($page->filter('[data-testid="crm-link-form"] form')->form(), ['crm_link[hostType]' => 'project', 'crm_link[hostId]' => "p\u{200B}1"]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_host_link'));
        $this->client->request('POST', '/crm/companies/' . $acme . '/notes');
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));

        $path = (string) tempnam(sys_get_temp_dir(), 'crm');
        file_put_contents($path, "email,last_name,VIP\na@x.test,A,1\n");
        $upload = $this->client->request('GET', '/crm/import')->filter('[data-testid="crm-import-upload"] form')->form();
        $this->client->submit($upload, ['crm_import[subject]' => 'contact', 'crm_import[file]' => new UploadedFile($path, 'people.csv', 'text/csv', null, true)]);
        $preview = $this->client->followRedirect();
        self::assertStringContainsString('VIP (vip)', $preview->html());
        $this->client->request('POST', (string) $this->client->getRequest()->getUri(), ['crm_import_confirm' => ['col_0' => 'email']]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'no CSRF token');
    }

    public function testAnUploadOverTheSizeLimitIsRefusedBeforeItIsRead(): void
    {
        $this->boot(['import' => ['max_bytes' => 1024]]);
        $this->admin();
        $path = (string) tempnam(sys_get_temp_dir(), 'crm');
        file_put_contents($path, "name\n" . str_repeat("Acme Tools Incorporated\n", 100));
        $upload = $this->client->request('GET', '/crm/import')->filter('[data-testid="crm-import-upload"] form')->form();
        $this->client->submit($upload, ['crm_import[subject]' => 'company', 'crm_import[file]' => new UploadedFile($path, 'big.csv', 'text/csv', null, true)]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_import'));
    }

    public function testAWriteInsideARunningTransactionIsRefusedAndAReadRunsInIt(): void
    {
        $this->boot();
        $this->admin();
        $id         = $this->company('Before');
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $this->company('Inside');
            self::fail('wrote inside the host transaction');
        } catch (LogicException $e) {
            self::assertStringContainsString('call it outside your transaction', $e->getMessage());
        }
        self::assertSame('Before', $this->service(CrmReaderInterface::class)->record(SubjectType::Company, $id)->label);
        $connection->rollBack();
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));
    }
}
