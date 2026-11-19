<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The pages (contract section 11.1): lists, forms, the 360° page and its blocks, merge, import, administration, the
 * 404 / 403 / 409 answers.
 */
final class UiTest extends CrmTestCase
{
    public function testCompanyPagesEndToEnd(): void
    {
        $this->boot();
        $this->admin();
        $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'tier', 'Tier', FieldType::Choice, ['Gold'], false, 1);
        $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'vip', 'VIP', FieldType::Boolean, [], false, 2);
        $tag = $this->service(TagServiceInterface::class)->create('Key', 'blue');
        self::assertSame(303, $this->httpStatus('GET', '/crm'));

        $page = $this->client->request('GET', '/crm/companies/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('[data-testid="crm-record-form"] form')->form();
        $this->client->submit($form, ['crm_record[name]' => '', 'crm_record[website]' => 'nope']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_record[name]' => 'Acme', 'crm_record[website]' => 'https://acme.test', 'crm_record[owner]' => 'leo', 'crm_record[custom_tier]' => 'Gold', 'crm_record[custom_vip]' => '1', 'crm_record[tags]' => [$tag]]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $show = (string) $this->client->getResponse()->headers->get('Location');
        $page = $this->client->request('GET', $show);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Acme', 'Leo', 'Gold', 'Key', 'data-testid="crm-timeline"', 'data-testid="crm-focus"'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        $id = basename($show);

        $edit = $this->client->request('GET', '/crm/companies/' . $id . '/edit');
        $form = $edit->filter('[data-testid="crm-record-form"] form')->form();
        $this->client->submit($form, ['crm_record[city]' => 'Madrid']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_record[city]' => 'Bilbao']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'the form posted version 1 again');
        self::assertStringContainsString('Madrid', (string) $this->client->getResponse()->getContent());
        $this->client->submit($form, ['crm_record[website]' => 'nope']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-note-form"] form')->form(), ['crm_note[body]' => 'First call']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        self::assertStringContainsString('First call', $page->filter('[data-testid="crm-notes"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-note-edit"] form')->form(), ['crm_note[body]' => 'Edited call']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-note-edit"] form')->form(), ['crm_note[body]' => 'x', 'crm_note[version]' => '1']);
        $this->client->followRedirect();
        self::assertStringContainsString('same moment', (string) $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-notes"] form.nowo-ui-action-form, [data-testid="crm-notes"] form')->last()->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));

        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-tags-form"] form')->form(), ['crm_tags[tags]' => []]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-link-form"] form')->form(), ['crm_link[hostType]' => 'project', 'crm_link[hostId]' => 'p1', 'crm_link[label]' => '']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        self::assertStringContainsString('Website', $page->filter('[data-testid="crm-links"]')->text());
        self::assertStringContainsString('Project p1', $page->filter('[data-testid="crm-panel"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-link-form"] form')->form(), ['crm_link[hostType]' => 'project', 'crm_link[hostId]' => 'p1 ']);
        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-link"] form')->form());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_host_link'));

        $list = $this->client->request('GET', '/crm/companies?q=acm&sort=label&direction=desc&tag=' . $tag . '&owner=leo&page=1&per_page=10');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Acme', $list->filter('[data-testid="crm-list-company"]')->text());
        $this->client->request('GET', '/crm/companies/export.csv');
        self::assertSame('text/csv; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->submit($this->client->request('GET', $show)->filter('form[action$="/trash"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $trash = $this->client->request('GET', '/crm/companies?status=trashed');
        self::assertStringContainsString('Acme', $trash->text());
        $this->client->submit($this->client->request('GET', $show)->filter('form[action$="/restore"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($this->client->request('GET', $show)->filter('form[action$="/trash"]')->form());
        $this->client->request('GET', '/crm/companies/' . $id . '/edit');
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'a trashed record is not edited');
        $this->client->submit($this->client->request('GET', $show)->filter('form[action$="/purge"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->httpStatus('GET', $show));
        $this->client->request('POST', '/crm/companies/' . $id . '/trash');
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'no CSRF token: refused with a flash');
    }

    public function testContactPagesMergeAndGdpr(): void
    {
        $this->boot();
        $this->admin();
        $acme  = $this->company('Acme');
        $ada   = $this->contact('Ada', 'Lovelace', 'ada@x.test');
        $other = $this->contact('Ada', 'Lovelace', 'ada.l@x.test');
        $page  = $this->client->request('GET', '/crm/contacts/' . $ada);
        $this->client->submit($page->filter('[data-testid="crm-affiliation-form"] form')->form(), ['crm_affiliation[company]' => $acme, 'crm_affiliation[role]' => 'CTO', 'crm_affiliation[primary]' => '1']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertStringContainsString('CTO', $page->filter('[data-testid="crm-affiliations"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-consent-form"] form')->form(), ['crm_consent[purpose]' => 'newsletter', 'crm_consent[granted]' => '1', 'crm_consent[evidence]' => 'paper form']);
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertStringContainsString('paper form', $page->filter('[data-testid="crm-consents"]')->text());
        self::assertStringContainsString('Ada Lovelace', $this->client->request('GET', '/crm/companies/' . $acme)->filter('[data-testid="crm-affiliations"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-affiliation"] form')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/crm/contacts/' . $ada . '/vcard');
        self::assertStringContainsString('BEGIN:VCARD', (string) $this->client->getResponse()->getContent());
        self::assertSame(405, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/gdpr-export'), 'a POST with the action token only');
        self::assertCount(1, $this->client->request('GET', '/crm/contacts/' . $ada)->filter('form[action$="/gdpr-export"]'), 'the page posts it');
        $this->client->request('POST', '/crm/contacts/' . $ada . '/gdpr-export', ['crm_action' => ['_token' => $this->actionToken('/crm/contacts/' . $ada)]]);
        self::assertStringContainsString('"email":"ada@x.test"', (string) $this->client->getResponse()->getContent());

        $pick = $this->client->request('GET', '/crm/contacts/' . $ada . '/merge');
        self::assertCount(1, $pick->filter('[data-testid="crm-merge-duplicate"]'));
        $this->client->submit($pick->filter('[data-testid="crm-merge"] form, form[name="crm_merge_pick"]')->form(), ['crm_merge_pick[other]' => 'nope']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($pick->filter('form[name="crm_merge_pick"]')->form(), ['crm_merge_pick[other]' => $other]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $preview = $this->client->followRedirect();
        self::assertStringContainsString('ada.l@x.test', $preview->filter('[data-testid="crm-merge-preview"]')->text());
        $form = $preview->filter('form[name="crm_merge"]')->form();
        $take = $form['crm_merge[take_2]'];
        self::assertInstanceOf(ChoiceFormField::class, $take);
        $take->tick();
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(303, $this->httpStatus('GET', '/crm/contacts/' . $other), 'a merged id redirects to the survivor');
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'a second merge: flash and back');

        $this->client->submit($this->client->request('GET', '/crm/contacts/' . $ada)->filter('form[action$="/erase"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertStringContainsString('Erased contact', $page->text());
        self::assertSame(303, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/edit'));
    }

    public function testImportPages(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/import');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('[data-testid="crm-import-upload"] form')->form();
        $this->client->submit($form, ['crm_import[file]' => $this->file("name\n")]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_import[subject]' => 'company', 'crm_import[file]' => $this->file("name,city\nAcme,Madrid\nBeta,Bilbao\n")]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $preview = $this->client->followRedirect();
        self::assertSame('preview', $preview->filter('[data-testid="crm-import"]')->attr('data-status'));
        $confirm = $preview->filter('[data-testid="crm-import"] form[name="crm_import_confirm"]')->form();
        $this->client->submit($confirm, ['crm_import_confirm[col_0]' => '', 'crm_import_confirm[col_1]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($confirm, ['crm_import_confirm[col_0]' => 'name', 'crm_import_confirm[col_1]' => 'city']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $done = $this->client->followRedirect();
        self::assertSame('done', $done->filter('[data-testid="crm-import-summary"]')->attr('data-status'));
        $this->client->submit($confirm);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'a second confirm: flash');

        $this->client->submit($form, ['crm_import[subject]' => 'company', 'crm_import[file]' => $this->file("name\nGamma\n")]);
        $preview = $this->client->followRedirect();
        $this->client->submit($preview->filter('button[data-testid="crm-import-cancel"]')->form());
        self::assertSame('cancelled', $this->client->followRedirect()->filter('[data-testid="crm-import-summary"]')->attr('data-status'));
        $this->client->request('POST', (string) $this->client->getRequest()->getUri(), ['do' => 'run']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'no CSRF token');

        $rows = "name\n";
        for ($i = 0; $i < 600; ++$i) {
            $rows .= 'Company ' . $i . "\n";
        }
        $this->client->submit($form, ['crm_import[subject]' => 'company', 'crm_import[file]' => $this->file($rows)]);
        $preview = $this->client->followRedirect();
        $this->client->submit($preview->filter('form[name="crm_import_confirm"]')->form());
        $running = $this->client->followRedirect();
        self::assertSame('running', $running->filter('[data-testid="crm-import-summary"]')->attr('data-status'));
        $this->client->submit($running->filter('button[data-testid="crm-import-continue"]')->form());
        self::assertSame('done', $this->client->followRedirect()->filter('[data-testid="crm-import-summary"]')->attr('data-status'));
    }

    public function testAdministrationPages(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/admin/tags');
        $form = $page->filter('[data-testid="crm-tag-form"] form')->form();
        $this->client->submit($form, ['crm_tag[name]' => 'VIP', 'crm_tag[colour]' => 'red']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_tag[name]' => 'vip', 'crm_tag[colour]' => 'red']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $list    = $this->client->request('GET', '/crm/admin/tags');
        $edit    = $this->client->click($list->filter('[data-testid="crm-tags"] a')->reduce(static fn ($a): bool => str_contains((string) $a->attr('href'), '/admin/tags/'))->link());
        $tagForm = $edit->filter('form[name="crm_tag"]')->form();
        $this->client->submit($tagForm, ['crm_tag[name]' => 'Gold']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($tagForm, ['crm_tag[name]' => 'Again']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'stale: flash');
        $edit = $this->client->followRedirect();
        $this->client->submit($edit->filter('form[name="crm_tag"]')->form(), ['crm_tag[name]' => ' ']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($edit->filter('form[name="crm_action"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/tags/01900000-0000-7000-8000-00000000ffff'));

        $page = $this->client->request('GET', '/crm/admin/fields?for=contact');
        $form = $page->filter('[data-testid="crm-field-form"] form')->form();
        $this->client->submit($form, ['crm_field[key]' => 'Bad Key', 'crm_field[label]' => 'X']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_field[key]' => 'tier', 'crm_field[label]' => 'Tier', 'crm_field[type]' => 'choice', 'crm_field[choices]' => "A\nB\n\n", 'crm_field[required]' => '1', 'crm_field[position]' => '1']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $fields = $this->client->request('GET', '/crm/admin/fields');
        self::assertStringContainsString('Tier', $fields->text());
        $edit      = $this->client->click($fields->filter('[data-testid="crm-fields"] a')->reduce(static fn ($a): bool => str_contains((string) $a->attr('href'), '/admin/fields/'))->link());
        $fieldForm = $edit->filter('form[name="crm_field"]')->form();
        $this->client->submit($fieldForm, ['crm_field[label]' => 'Level']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($fieldForm, ['crm_field[label]' => 'Again']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'stale: flash');
        $edit = $this->client->followRedirect();
        $this->client->submit($edit->filter('form[name="crm_field"]')->form(), ['crm_field[label]' => ' ']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $archive = $edit->filter('form[name="crm_action"]')->form();
        $this->client->submit($archive);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_field WHERE archived = ?', [true]));
        $this->client->submit($archive);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'the version the page showed moved: a flash, no blind toggle');
        self::assertStringContainsString('/admin/fields/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_field WHERE archived = ?', [true]), 'still archived');
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/fields/01900000-0000-7000-8000-00000000ffff'));

        $page = $this->client->request('GET', '/crm/admin/settings');
        $form = $page->filter('[data-testid="crm-settings"] form')->form();
        $this->client->submit($form, ['crm_settings[trashDays]' => '3']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_settings[trashDays]' => '60']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_settings[trashDays]' => '61']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'stale: flash');
    }

    public function testTheGateAndJsonAnswers(): void
    {
        $this->boot();
        $this->admin();
        $id = $this->company('Acme');
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $id, 'n');
        $this->service(HostLinkServiceInterface::class)->link(SubjectType::Company, $id, new HostObjectRef('project', 'p1'));
        $token = $this->actionToken('/crm/companies/' . $id);
        $this->actAs('zoe', 'view');
        self::assertSame(200, $this->httpStatus('GET', '/crm/companies/' . $id));
        self::assertSame(403, $this->httpStatus('GET', '/crm/companies/new'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/tags'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/fields'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/settings'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/companies/export.csv'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/import'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/companies/01900000-0000-7000-8000-00000000ffff'));
        $this->ctx()->tenant = 'beta';
        $this->ctx()->grant('beta', 'zoe', 'view', 'edit');
        self::assertSame(404, $this->httpStatus('GET', '/crm/companies/' . $id));
        self::assertSame(404, $this->httpStatus('GET', '/crm/import/' . $id));
        $this->ctx()->tenant          = 'acme';
        $this->ctx()->enabled['acme'] = false;
        self::assertSame(404, $this->httpStatus('GET', '/crm/companies'));
        $this->ctx()->enabled = [];
        $this->ctx()->actor   = null;
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts/' . $id . '/vcard'));
        $this->actAs('ana');
        $this->client->xmlHttpRequest('POST', '/crm/contacts/' . $id . '/gdpr-export', ['crm_action' => ['_token' => $token]]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function file(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'crm');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }
}
