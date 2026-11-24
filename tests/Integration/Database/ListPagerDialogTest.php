<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\AdminController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordController;
use Symfony\Component\DomCrawler\Crawler;

use function sprintf;

/**
 * 0.8.0 (Beacon requests 2026-11-23): "Rows per page" in the filter card of the lists (`ui.list_per_page`), the pager
 * with its count under every list, "New company / contact" in a remote form dialog loaded on demand
 * (`ui.record_create: dialog`) and the filter card + pager on Tags and Custom fields.
 */
final class ListPagerDialogTest extends CrmTestCase
{
    private const DIALOG = ['HTTP_X_NOWO_DIALOG' => '1'];

    public function testThePagerCountShowsUnderASinglePageAndTheSelectIsOptIn(): void
    {
        $this->boot();
        $this->admin();
        $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies');
        self::assertCount(0, $page->filter('select[name="per_page"]'), 'no select without ui.list_per_page');
        $pager = $page->filter('[data-testid="crm-list-company"] [data-nowo-ui-region="pagination"]');
        self::assertCount(1, $pager, 'the pager under one page');
        self::assertStringContainsString('1', $pager->filter('.nowo-ui-pagination__info')->text());
        self::assertCount(0, $pager->filter('nav'), 'no page links for one page');
    }

    public function testRowsPerPageAreCheckedAgainstTheChoices(): void
    {
        $this->boot(['ui' => ['list_per_page' => [10, 25, 50]]]);
        $this->admin();
        for ($i = 1; $i <= 12; ++$i) {
            $this->company(sprintf('Company %02d', $i));
        }
        $page   = $this->client->request('GET', '/crm/companies?per_page=10');
        $select = $page->filter('[data-nowo-ui-region="filters"] select[name="per_page"]');
        self::assertCount(1, $select, 'the select sits in the filter card');
        self::assertSame(['10', '25', '50'], $select->filter('option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('10', $select->filter('option[selected]')->attr('value'));
        self::assertCount(1, $page->filter('label[for="per_page"]'), 'a visible label');
        self::assertSame(10, $this->rows($page, 'crm-list-company'));
        $pager = $page->filter('[data-nowo-ui-region="pagination"]');
        self::assertStringContainsString('12', $pager->text());
        self::assertCount(1, $pager->filter('nav'));
        self::assertStringContainsString('per_page=10', (string) $pager->filter('nav a')->last()->attr('href'), 'the pages keep the rows per page');

        self::assertSame(2, $this->rows($this->client->request('GET', '/crm/companies?per_page=10&page=2'), 'crm-list-company'));
        self::assertSame(2, $this->rows($this->client->request('GET', '/crm/companies?per_page=10&page=99'), 'crm-list-company'), 'a page past the end shows the last one');
        foreach (['7', '100', '0', '-5', 'abc', '25.5'] as $bad) {
            $page = $this->client->request('GET', '/crm/companies?per_page=' . $bad);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $bad);
            self::assertSame(12, $this->rows($page, 'crm-list-company'), $bad . ': the page size (25)');
            self::assertSame('25', $page->filter('select[name="per_page"] option[selected]')->attr('value'), $bad . ': the select shows the value used');
        }
        $this->client->request('GET', '/crm/companies?per_page[]=10');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'an array is ignored');
    }

    public function testWithoutChoicesTheUiKitValuesAndThePageSizeAreAccepted(): void
    {
        $this->boot(['ui' => ['page_size' => 15]]);
        $this->admin();
        for ($i = 1; $i <= 12; ++$i) {
            $this->contact('Ada' . $i, 'Lovelace');
        }
        self::assertSame(10, $this->rows($this->client->request('GET', '/crm/contacts?per_page=10'), 'crm-list-contact'));
        self::assertSame(12, $this->rows($this->client->request('GET', '/crm/contacts?per_page=7'), 'crm-list-contact'), 'not a UiKit value: the page size');
        self::assertSame(12, $this->rows($this->client->request('GET', '/crm/contacts'), 'crm-list-contact'));
    }

    public function testNewRecordStaysAPageByDefault(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/companies');
        // 0.10.2: one create button — on an empty list the empty state's, none in the header.
        self::assertCount(0, $page->filter('[data-testid="crm-record-new"]'));
        $new = $page->filter('[data-testid="crm-record-new-empty"]');
        self::assertCount(1, $new);
        self::assertStringEndsWith('/crm/companies/new', (string) $new->attr('href'));
        self::assertNull($new->attr('data-nowo-ui-dialog-open'));
        self::assertCount(0, $page->filter('dialog#' . RecordController::RECORD_DIALOG));
        $this->client->request('GET', '/crm/companies/new', [], [], self::DIALOG);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<html', (string) $response->getContent(), 'the full page: the dialog is off');
        self::assertNotContains('X-Nowo-Dialog', $response->getVary());
    }

    public function testNewCompanyInTheDialog(): void
    {
        $this->boot(['ui' => ['record_create' => 'dialog']]);
        $this->admin();
        $list = $this->client->request('GET', '/crm/companies');
        $new  = $list->filter('[data-testid="crm-record-new-empty"]');
        self::assertSame('a', $new->nodeName(), 'a real link: the page without JavaScript');
        self::assertStringEndsWith('/crm/companies/new', (string) $new->attr('href'));
        self::assertSame(RecordController::RECORD_DIALOG, $new->attr('data-nowo-ui-dialog-open'));
        self::assertSame($new->attr('href'), $new->attr('data-nowo-ui-dialog-src'));
        self::assertNotNull($new->attr('data-nowo-ui-dialog-form'));
        self::assertSame('New company', $new->attr('data-nowo-ui-dialog-title'));
        self::assertSame('dialog', $new->attr('aria-haspopup'));
        $dialog = $list->filter('dialog#' . RecordController::RECORD_DIALOG);
        self::assertCount(1, $dialog, 'one empty shell');
        self::assertNotNull($dialog->attr('data-nowo-ui-remote-form'));
        self::assertCount(0, $list->filter('form[name="crm_record"]'), 'the form is not in the list page (loaded on demand)');
        $empty = $list->filter('[data-testid="crm-record-new-empty"]');
        self::assertSame(RecordController::RECORD_DIALOG, $empty->attr('data-nowo-ui-dialog-open'), 'the empty state opens it too');

        $full = $this->client->request('GET', '/crm/companies/new');
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent(), 'the no-JS page');
        self::assertContains('X-Nowo-Dialog', $this->client->getResponse()->getVary());
        self::assertCount(1, $full->filter('[data-testid="crm-record-form"] form[name="crm_record"]'));

        $fragment = $this->client->request('GET', '/crm/companies/new', [], [], self::DIALOG);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertContains('X-Nowo-Dialog', $response->getVary());
        self::assertStringNotContainsString('<html', (string) $response->getContent(), 'a fragment');
        $form = $fragment->filter('[data-testid="crm-record-dialog-form"] form[name="crm_record"]');
        self::assertCount(1, $form);
        self::assertStringEndsWith('/crm/companies/new', (string) $form->attr('action'));
        self::assertSame('crm-record-dialog-title', $form->attr('aria-labelledby'));
        self::assertCount(1, $form->filter('[data-nowo-ui-dialog-actions] button[type="submit"]'));
        self::assertCount(1, $form->filter('[data-nowo-ui-dialog-actions] button[type="button"][data-nowo-ui-dialog-close]'));
        self::assertCount(1, $form->filter('input[name="crm_record[_token]"]'), 'the CSRF token of the page');

        $invalid = $this->client->submit($form->form(), ['crm_record[name]' => ''], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent(), 'the invalid form in the dialog');
        self::assertCount(1, $invalid->filter('form[name="crm_record"]'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));

        $this->client->submit($form->form(), ['crm_record[name]' => 'Acme', 'crm_record[_token]' => 'forged'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'CSRF unchanged');
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));

        $this->client->submit($form->form(), ['crm_record[name]' => 'Acme'], self::DIALOG);
        $saved = $this->client->getResponse();
        self::assertSame(204, $saved->getStatusCode());
        $id = $this->text('SELECT id FROM nowo_crm_company');
        self::assertSame('/crm/companies/' . $id, $saved->headers->get('X-Nowo-Redirect'), 'the new record, same origin');
        self::assertContains('X-Nowo-Dialog', $saved->getVary());
        $show = $this->client->request('GET', '/crm/companies/' . $id);
        self::assertStringContainsString('Acme', $show->text());

        $this->client->submit($full->filter('form[name="crm_record"]')->form(), ['crm_record[name]' => 'Beta']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'the page keeps its redirect');
        self::assertContains('X-Nowo-Dialog', $this->client->getResponse()->getVary());
    }

    public function testNewContactInTheDialogAndAnInvalidPageSubmission(): void
    {
        $this->boot(['ui' => ['record_create' => 'dialog']]);
        $this->admin();
        $new = $this->client->request('GET', '/crm/contacts')->filter('[data-testid="crm-record-new-empty"]');
        self::assertSame('New contact', $new->attr('data-nowo-ui-dialog-title'));
        $form = $this->client->request('GET', '/crm/contacts/new', [], [], self::DIALOG)->filter('form[name="crm_record"]')->form();
        $this->client->submit($form, ['crm_record[first_name]' => 'Ada', 'crm_record[last_name]' => 'Lovelace'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('/crm/contacts/', (string) $this->client->getResponse()->headers->get('X-Nowo-Redirect'));

        $page = $this->client->request('GET', '/crm/contacts/new');
        $this->client->submit($page->filter('form[name="crm_record"]')->form(), ['crm_record[first_name]' => '', 'crm_record[last_name]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent(), 'the page answers the page');
    }

    public function testAPlanLimitStaysInTheDialogAndTheConflictPageVaries(): void
    {
        $this->boot(['ui' => ['record_create' => 'dialog']]);
        $this->admin();
        $this->company('First');
        $this->ctx()->limits['companies'] = 1;
        $form                             = $this->client->request('GET', '/crm/companies/new', [], [], self::DIALOG)->filter('form[name="crm_record"]')->form();
        $again                            = $this->client->submit($form, ['crm_record[name]' => 'Second'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'the dialog re-renders it in place');
        self::assertCount(1, $again->filter('[data-testid="crm-record-dialog-form-errors"][role="alert"]'), 'the reason next to the typed form');
        self::assertSame('Second', $again->filter('input[name="crm_record[name]"]')->attr('value'));

        $page = $this->client->request('GET', '/crm/companies/new');
        $this->client->submit($page->filter('form[name="crm_record"]')->form(), ['crm_record[name]' => 'Second']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'the page keeps its 409');
        self::assertContains('X-Nowo-Dialog', $this->client->getResponse()->getVary());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));
    }

    public function testTheFragmentKeepsTheGateAndTheTenant(): void
    {
        $this->boot(['ui' => ['record_create' => 'dialog']]);
        $this->actAs('zoe', 'view');
        $list = $this->client->request('GET', '/crm/companies');
        self::assertCount(0, $list->filter('[data-testid="crm-record-new"], [data-testid="crm-record-new-empty"]'), 'no opener without create');
        self::assertCount(0, $list->filter('dialog#' . RecordController::RECORD_DIALOG), 'no shell either');
        $this->client->request('GET', '/crm/companies/new', [], [], self::DIALOG);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'the header is not a pass');
        $this->client->request('POST', '/crm/companies/new', ['crm_record' => ['name' => 'Sneaky']], [], self::DIALOG);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));

        $this->admin();
        $form = $this->client->request('GET', '/crm/companies/new', [], [], self::DIALOG)->filter('form[name="crm_record"]')->form();
        $this->client->submit($form, ['crm_record[name]' => 'Acme only'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $target = (string) $this->client->getResponse()->headers->get('X-Nowo-Redirect');

        $this->ctx()->tenant = 'globex';
        $this->admin();
        self::assertStringNotContainsString('Acme only', $this->client->request('GET', '/crm/companies')->text(), 'another tenant does not see it');
        self::assertSame(404, $this->httpStatus('GET', $target));
        $this->ctx()->tenant = 'acme';
    }

    public function testTagsHaveTheFilterCardAndThePager(): void
    {
        $this->boot(['ui' => ['list_per_page' => [10, 25]]]);
        $this->admin();
        $tags = $this->service(TagServiceInterface::class);
        for ($i = 1; $i <= 12; ++$i) {
            $tags->create(sprintf('Tag %02d', $i), 'blue');
        }
        $tags->create('Key account', 'red');
        $page = $this->client->request('GET', '/crm/admin/tags');
        self::assertCount(1, $page->filter('[data-nowo-ui-region="filters"] input[name="q"]'));
        self::assertCount(1, $page->filter('[data-nowo-ui-region="filters"] select[name="per_page"]'));
        self::assertSame(13, $this->rows($page, 'crm-tags'));
        self::assertStringContainsString('13', $page->filter('[data-testid="crm-tags"] [data-nowo-ui-region="pagination"]')->text());

        $page = $this->client->request('GET', '/crm/admin/tags?per_page=10&page=2');
        self::assertSame(3, $this->rows($page, 'crm-tags'));
        self::assertSame('10', $page->filter('select[name="per_page"] option[selected]')->attr('value'));
        self::assertSame(13, $this->rows($this->client->request('GET', '/crm/admin/tags?per_page=50'), 'crm-tags'), 'not a choice: the page size');

        $page = $this->client->request('GET', '/crm/admin/tags?q=KEY');
        self::assertSame(1, $this->rows($page, 'crm-tags'), 'case-insensitive name search');
        self::assertSame('KEY', $page->filter('input[name="q"]')->attr('value'));
        $none = $this->client->request('GET', '/crm/admin/tags?q=nothing');
        self::assertStringContainsString('Nothing matches these filters.', $none->filter('[data-testid="crm-tags"]')->text());
        $this->client->request('GET', '/crm/admin/tags?q[]=x');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testCustomFieldsHaveTheFilterCardAndThePager(): void
    {
        $this->boot();
        $this->admin();
        $fields = $this->service(FieldServiceInterface::class);
        $fields->define(SubjectType::Company, 'tier', 'Tier', FieldType::Text, [], false, 1);
        $fields->define(SubjectType::Contact, 'shoe', 'Shoe size', FieldType::Text, [], false, 1);
        $fields->define(SubjectType::Contact, 'vip', 'Important', FieldType::Boolean, [], false, 2);
        $page = $this->client->request('GET', '/crm/admin/fields');
        self::assertCount(1, $page->filter('[data-nowo-ui-region="filters"] input[name="q"]'));
        self::assertCount(0, $page->filter('select[name="per_page"]'), 'no select without ui.list_per_page');
        self::assertSame(3, $this->rows($page, 'crm-fields'));
        self::assertCount(1, $page->filter('[data-testid="crm-fields"] [data-nowo-ui-region="pagination"]'));
        self::assertSame(1, $this->rows($this->client->request('GET', '/crm/admin/fields?q=vip'), 'crm-fields'), 'the key matches');
        self::assertSame(1, $this->rows($this->client->request('GET', '/crm/admin/fields?q=shoe+SIZE'), 'crm-fields'), 'the label matches');
        self::assertSame(3, $this->rows($this->client->request('GET', '/crm/admin/fields?q=+'), 'crm-fields'), 'a blank search is no search');
        self::assertStringContainsString('Nothing matches these filters.', $this->client->request('GET', '/crm/admin/fields?q=zzz')->text());

        $this->actAs('zoe', 'view', 'edit');
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/fields?q=vip'), 'the filters keep the gate');
    }

    public function testNewTagLoadsOnDemand(): void
    {
        $this->boot(['ui' => ['admin_create' => 'dialog']]);
        $this->admin();
        $tags = $this->client->request('GET', '/crm/admin/tags');
        self::assertContains('X-Nowo-Dialog', $this->client->getResponse()->getVary());
        $new = $tags->filter('[data-testid="crm-tag-new"]');
        self::assertSame('a', $new->nodeName());
        self::assertStringEndsWith('/crm/admin/tags?new=1', (string) $new->attr('href'), 'the no-JS fallback');
        self::assertSame(AdminController::TAG_DIALOG, $new->attr('data-nowo-ui-dialog-open'));
        self::assertSame($new->attr('href'), $new->attr('data-nowo-ui-dialog-src'));
        self::assertNotNull($new->attr('data-nowo-ui-dialog-form'));
        self::assertNull($new->attr('data-nowo-confirm-open'), 'not the 0.6.1 in-page dialog');
        $shell = $tags->filter('dialog#' . AdminController::TAG_DIALOG);
        self::assertCount(1, $shell);
        self::assertNotNull($shell->attr('data-nowo-ui-remote-form'));
        self::assertCount(0, $tags->filter('form[name="crm_tag"]'), 'no create form in the page');
        self::assertCount(0, $tags->filter('noscript'));

        $deep = $this->client->request('GET', '/crm/admin/tags?new=1');
        self::assertCount(1, $deep->filter('[data-testid="crm-tag-form"] form[name="crm_tag"]'), 'the fallback card');

        $fragment = $this->client->request('GET', '/crm/admin/tags?new=1', [], [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertContains('X-Nowo-Dialog', $this->client->getResponse()->getVary());
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $fragment->filter('table'), 'no list in the fragment');
        $form = $fragment->filter('[data-testid="crm-tag-form-dialog-form"] form[name="crm_tag"]');
        self::assertCount(1, $form);
        self::assertSame('crm-tag-dialog-title', $form->attr('aria-labelledby'));
        self::assertCount(1, $form->filter('[data-nowo-ui-dialog-actions] button[data-nowo-ui-dialog-close]'));

        $this->client->submit($form->form(), ['crm_tag[name]' => 'VIP', 'crm_tag[colour]' => 'red'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('/crm/admin/tags', $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        $again = $this->client->submit($form->form(), ['crm_tag[name]' => 'vip', 'crm_tag[colour]' => 'red'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'a duplicate stays in the dialog');
        self::assertCount(1, $again->filter('form[name="crm_tag"]'));
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        $this->client->submit($form->form(), ['crm_tag[name]' => 'Other', 'crm_tag[_token]' => 'forged'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'CSRF unchanged');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_tag'));

        // Without JavaScript: the page, 422 with the card, 303 once saved.
        $page = $this->client->submit($deep->filter('form[name="crm_tag"]')->form(), ['crm_tag[name]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('[data-testid="crm-tag-form"] form[name="crm_tag"]'));
        $this->client->submit($deep->filter('form[name="crm_tag"]')->form(), ['crm_tag[name]' => 'Plain']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
    }

    public function testNewFieldLoadsOnDemandPerSubject(): void
    {
        $this->boot(['ui' => ['admin_create' => 'dialog']]);
        $this->admin();
        $fields = $this->client->request('GET', '/crm/admin/fields');
        foreach (['company' => 'New field for companies', 'contact' => 'New field for contacts'] as $subject => $title) {
            $new = $fields->filter('[data-testid="crm-field-new-' . $subject . '"]');
            self::assertSame(AdminController::FIELD_DIALOG, $new->attr('data-nowo-ui-dialog-open'), 'one shell');
            self::assertStringEndsWith('for=' . $subject . '&new=1', (string) $new->attr('data-nowo-ui-dialog-src'));
            self::assertSame($title, $new->attr('data-nowo-ui-dialog-title'));
        }
        self::assertCount(1, $fields->filter('dialog#' . AdminController::FIELD_DIALOG));
        self::assertCount(0, $fields->filter('form[name="crm_field"]'));
        self::assertCount(0, $fields->filter('[data-testid="crm-fields-for"]'));

        $form = $this->client->request('GET', '/crm/admin/fields?for=contact&new=1', [], [], self::DIALOG)->filter('form[name="crm_field"]');
        self::assertStringEndsWith('for=contact', (string) $form->attr('action'));
        $this->client->submit($form->form(), ['crm_field[key]' => 'Bad Key', 'crm_field[label]' => 'X'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form->form(), ['crm_field[key]' => 'tier', 'crm_field[label]' => 'Tier'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('/crm/admin/fields?for=contact', $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_field WHERE subject_type = ?', ['contact']));
        self::assertCount(1, $this->client->request('GET', '/crm/admin/fields?for=company&new=1')->filter('[data-testid="crm-field-form"] form[name="crm_field"]'), 'the fallback card');
    }

    public function testAdminFragmentsKeepTheGate(): void
    {
        $this->boot(['ui' => ['admin_create' => 'dialog']]);
        $this->actAs('zoe', 'view', 'edit');
        $this->client->request('GET', '/crm/admin/tags?new=1', [], [], self::DIALOG);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/crm/admin/tags', ['crm_tag' => ['name' => 'X']], [], self::DIALOG);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/crm/admin/fields?for=contact&new=1', [], [], self::DIALOG);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_tag'));
    }

    /**
     * The body rows of a list.
     */
    private function rows(Crawler $page, string $testid): int
    {
        return $page->filter('[data-testid="' . $testid . '"] tbody tr')->count();
    }
}
