<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\AdminController;

use function basename;
use function str_contains;
use function trim;

/**
 * UX review 1.171 (0.6.0): row actions in the UiKit menu, split headers, the record page without list tabs, the filter
 * placeholders, the filters slot, checkboxes without the text input class, tag swatches and UiKit empty states.
 */
final class UiPresentationTest extends CrmTestCase
{
    public function testDefaultsKeepEveryRowActionAsAButton(): void
    {
        $this->boot();
        $this->admin();
        $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies');
        self::assertCount(0, $page->filter('.crm-kit-row-actions'), 'the 0.5.0 cluster: no bundle partial');
        self::assertCount(0, $page->filter('.nowo-ui-row-actions--menu'));
        self::assertCount(1, $page->filter('[data-testid="crm-list-company"] form[action*="/trash"]'), 'Move to trash is a button of the row');
        self::assertCount(0, $page->filter('[data-nowo-ui-region="filters"] [data-testid="host-filters-slot"]'));
        self::assertCount(0, $page->filter('.nowo-ui-filters-collapse'), 'no fold by default');
    }

    public function testRowMenuMovesTheSecondaryActionsAndKeepsOpenAndEdit(): void
    {
        $this->boot(['ui' => ['row_actions' => 'menu']]);
        $this->admin();
        $gone = $this->company('Gone');
        $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies');
        self::assertCount(2, $page->filter('.crm-kit-row-actions'));
        self::assertCount(2, $page->filter('.crm-kit-row-actions .nowo-ui-row-actions--menu'), 'the kebab of each row');
        self::assertCount(2, $page->filter('.crm-kit-row-actions .nowo-ui-row-actions:not(.nowo-ui-row-actions--menu) a[href*="/edit"]'), 'Edit stays a button');
        self::assertCount(2, $page->filter('.nowo-ui-row-actions--menu .nowo-ui-action--delete'), 'Move to trash sits in the menu (its confirm dialog renders after the table)');
        self::assertCount(0, $page->filter('.nowo-ui-row-actions:not(.nowo-ui-row-actions--menu) .nowo-ui-action--delete'));
        $this->records()->trash(SubjectType::Company, $gone, 1);
        $trash = $this->client->request('GET', '/crm/companies?status=trashed');
        self::assertCount(1, $trash->filter('.nowo-ui-row-actions--menu form[action*="/restore"]'), 'Restore sits in the menu too');
    }

    public function testRecordHeaderIsSplitAndTabsCanBeHidden(): void
    {
        $this->boot();
        $this->admin();
        $id   = $this->contact('Ada', 'Lovelace');
        $page = $this->client->request('GET', '/crm/contacts/' . $id);
        self::assertCount(1, $page->filter('[data-nowo-ui-header-actions]'), 'split layout');
        self::assertCount(1, $page->filter('[data-nowo-ui-header-actions] a[href$="/edit"]'), 'Edit is an icon button');
        self::assertStringContainsString('visually-hidden', $page->filter('[data-nowo-ui-header-actions] a[href$="/edit"]')->html());
        $more = $page->filter('[data-nowo-ui-header-more]');
        self::assertCount(1, $more, 'the more menu');
        $menu = $more->html();
        foreach (['/merge', '/vcard', 'Move to trash', 'Erase personal data'] as $needle) {
            self::assertStringContainsString($needle, $menu);
        }
        self::assertGreaterThan((int) strpos($menu, '/vcard'), (int) strpos($menu, 'Move to trash'), 'destructive last');
        self::assertGreaterThan((int) strpos($menu, '/gdpr-export'), (int) strpos($menu, 'Erase personal data'), 'erase after the export');
        self::assertGreaterThan(0, $page->filter('nav[aria-label="CRM"]')->count(), 'the list tabs by default');

        $this->boot(['ui' => ['record_tabs' => false, 'record_actions' => 'toolbar']]);
        $this->admin();
        $id   = $this->contact('Ada', 'Lovelace');
        $page = $this->client->request('GET', '/crm/contacts/' . $id);
        self::assertCount(0, $page->filter('nav[aria-label="CRM"]'), 'no list tabs on a record page');
        self::assertCount(0, $page->filter('[data-nowo-ui-header-more]'), 'toolbar: every action is a button');
        $list = $this->client->request('GET', '/crm/contacts');
        self::assertGreaterThan(0, $list->filter('nav[aria-label="CRM"]')->count(), 'lists keep their tabs');
    }

    public function testHeaderActionsCanBeSplit(): void
    {
        $this->boot(['ui' => ['header_actions' => 'split']]);
        $this->admin();
        $this->actAs('ana', 'view', 'edit', 'manage', 'export');
        $page = $this->client->request('GET', '/crm/companies');
        self::assertCount(1, $page->filter('[data-nowo-ui-header-actions]'));
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/companies');
        self::assertCount(0, $page->filter('[data-nowo-ui-header-actions]'), 'toolbar by default');
    }

    public function testFiltersNameEveryFieldAndTheHostSlotWrapsThem(): void
    {
        $this->boot(['ui' => ['filters_slot' => '@CrmTest/filters_slot.html.twig', 'filters_fold' => true]]);
        $this->admin();
        $this->service(TagServiceInterface::class)->create('Key', 'blue');
        $page = $this->client->request('GET', '/crm/companies');
        $slot = $page->filter('[data-testid="host-filters-slot"]');
        self::assertCount(1, $slot, 'the host template wraps the filters');
        self::assertGreaterThan(0, (int) $slot->attr('data-fields'));
        self::assertCount(1, $slot->filter('form'), 'the UiKit filters are inside the slot');
        self::assertCount(1, $slot->filter('.nowo-ui-filters-collapse'), 'fold on');
        $html = $slot->html();
        foreach (['Search by name, e-mail or city', 'Any tag', 'Any role', 'Any owner'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringContainsString('aria-label="Search"', $html);
        self::assertStringNotContainsString('list.filter', $html, 'no raw key');
    }

    public function testCheckboxesDoNotTakeTheTextInputClass(): void
    {
        $this->boot();
        $this->admin();
        $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'vip', 'VIP', FieldType::Boolean, [], false, 1);
        $page = $this->client->request('GET', '/crm/companies/new');
        $box  = $page->filter('input[type="checkbox"][name="crm_record[custom_vip]"]');
        self::assertCount(1, $box);
        self::assertStringNotContainsString('nowo-ui-input', (string) $box->attr('class'));
        $fields = $this->client->request('GET', '/crm/admin/fields');
        self::assertStringNotContainsString('nowo-ui-input', (string) $fields->filter('input[type="checkbox"]')->first()->attr('class'));
    }

    public function testTagColoursAreSwatches(): void
    {
        $this->boot();
        $this->admin();
        $this->service(TagServiceInterface::class)->create('Key', 'blue');
        $page = $this->client->request('GET', '/crm/admin/tags');
        self::assertCount(1, $page->filter('[data-testid="crm-tag-swatch"].crm-kit-tag--blue'));
        $radios = $page->filter('[data-testid="crm-tag-form"] input[type="radio"][name="crm_tag[colour]"].crm-kit-swatch-input');
        self::assertCount(8, $radios);
        self::assertSame('neutral', $radios->first()->attr('data-colour'));
    }

    public function testEmptyBlocksOfARecordUseTheUiKitEmptyState(): void
    {
        $this->boot();
        $this->admin();
        $id   = $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies/' . $id);
        foreach (['crm-notes', 'crm-timeline', 'crm-affiliations'] as $testid) {
            $block = $page->filter('[data-testid="' . $testid . '"]');
            if ($testid === 'crm-timeline') {
                self::assertGreaterThanOrEqual(0, $block->count());
                continue;
            }
            self::assertCount(1, $block->filter('[data-nowo-ui-region="empty"]'), $testid);
        }
    }

    public function testRemoveLinksAreSecondaryCloseButtons(): void
    {
        $this->boot();
        $this->admin();
        $company = $this->company('Acme');
        $contact = $this->contact('Ada', 'Lovelace');
        $page    = $this->client->request('GET', '/crm/contacts/' . $contact);
        $form    = $page->filter('[data-testid="crm-affiliation-form"] form')->form();
        $this->client->submit($form, ['crm_affiliation[company]' => $company]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page   = $this->client->request('GET', '/crm/contacts/' . $contact);
        $remove = $page->filter('[data-testid="crm-affiliation"] form[action*="unaffiliate"], [data-testid="crm-affiliation"] form[action*="/affiliat"]');
        self::assertGreaterThan(0, $remove->count());
        $button = $remove->filter('button')->first();
        self::assertFalse(str_contains((string) $button->attr('class'), 'danger'), 'not the red trash');
        self::assertNotSame('', basename((string) $remove->first()->attr('action')));
    }

    public function testListTabsCanBeHiddenOnListAndAdminPages(): void
    {
        $this->boot();
        $this->admin();
        foreach (['/crm/companies', '/crm/admin/tags', '/crm/admin/fields', '/crm/admin/settings', '/crm/companies/new'] as $path) {
            self::assertGreaterThan(0, $this->client->request('GET', $path)->filter('nav[aria-label="CRM"]')->count(), 'tabs by default: ' . $path);
        }

        $this->boot(['ui' => ['list_tabs' => false]]);
        $this->admin();
        $id = $this->company('Acme');
        foreach (['/crm/companies', '/crm/contacts', '/crm/admin/tags', '/crm/admin/fields', '/crm/admin/settings', '/crm/companies/new', '/crm/companies/' . $id . '/edit'] as $path) {
            self::assertCount(0, $this->client->request('GET', $path)->filter('nav[aria-label="CRM"]'), 'no tabs: ' . $path);
        }
        self::assertGreaterThan(0, $this->client->request('GET', '/crm/companies/' . $id)->filter('nav[aria-label="CRM"]')->count(), 'the record page follows record_tabs');
    }

    public function testAdminEmptyStatesSayOneSentenceAndTheInlineFormsFollowTheList(): void
    {
        $this->boot();
        $this->admin();
        $tags  = $this->client->request('GET', '/crm/admin/tags');
        $empty = $tags->filter('[data-testid="crm-tags"] [data-nowo-ui-region="empty"]');
        self::assertCount(1, $empty);
        self::assertSame('No tags yet.', trim($empty->text()));
        self::assertCount(0, $tags->filter('[data-testid="crm-tags"] [data-testid="crm-tag-form"]'), 'the form is after the list section');
        self::assertCount(1, $tags->filter('[data-testid="crm-tag-form"] form'), 'inline by default');
        self::assertCount(0, $tags->filter('dialog'));

        $fields = $this->client->request('GET', '/crm/admin/fields?for=contact');
        self::assertSame('No custom fields yet.', trim($fields->filter('[data-testid="crm-fields"] [data-nowo-ui-region="empty"]')->text()));
        $switch = $fields->filter('[data-testid="crm-field-form"] nav[data-testid="crm-fields-for"]');
        self::assertCount(1, $switch, 'the subject switch sits inside the form card');
        self::assertCount(2, $switch->filter('a'));
        self::assertStringContainsString('for=contact', (string) $switch->filter('[aria-current="page"]')->attr('href'));
        self::assertStringContainsString('New field for contacts', $fields->filter('[data-testid="crm-field-form"]')->text());
    }

    public function testCreateDialogsOnTagsAndFields(): void
    {
        $this->boot(['ui' => ['admin_create' => 'dialog_inline']]);
        $this->admin();
        $tags = $this->client->request('GET', '/crm/admin/tags');
        $new  = $tags->filter('[data-testid="crm-tag-new"]');
        self::assertCount(1, $new, 'New tag is a header action');
        self::assertSame(AdminController::TAG_DIALOG, $new->attr('data-nowo-confirm-target'));
        self::assertStringEndsWith('/crm/admin/tags?new=1', (string) $new->attr('href'), 'the no-JS fallback');
        $dialog = $tags->filter('dialog#crm-tag-dialog');
        self::assertCount(1, $dialog);
        self::assertCount(1, $dialog->filter('.nowo-ui-dialog__body form[name="crm_tag"]'));
        self::assertSame('crm-tag-dialog-form', $dialog->filter('form')->attr('id'));
        self::assertCount(1, $dialog->filter('footer button[type="submit"][form="crm-tag-dialog-form"]'));
        self::assertCount(0, $tags->filter('#nowo-ui-open-modal-on-load'), 'closed on a plain visit');
        self::assertCount(0, $tags->filter('noscript'));
        self::assertStringNotContainsString('No items found', $tags->filter('[data-testid="crm-tags"]')->text());

        $deep = $this->client->request('GET', '/crm/admin/tags?new=1');
        self::assertSame('{"modal":"crm-tag-dialog","clearParam":"new"}', $deep->filter('#nowo-ui-open-modal-on-load')->text());
        self::assertStringContainsString('crm-tag-dialog-page_name', $deep->filter('noscript')->html(), 'the no-JS form, own ids');

        $form = $dialog->filter('form')->form();
        $this->client->submit($form, ['crm_tag[name]' => 'VIP', 'crm_tag[colour]' => 'red']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $again = $this->client->submit($form, ['crm_tag[name]' => 'vip', 'crm_tag[colour]' => 'red']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('{"modal":"crm-tag-dialog"}', $again->filter('#nowo-ui-open-modal-on-load')->text(), 'the dialog opens with the errors');
        self::assertGreaterThan(0, $again->filter('dialog#crm-tag-dialog .nowo-ui-dialog__body')->filter('[role="alert"], .nowo-ui-field-error, ul li')->count());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_tag'));

        $fields = $this->client->request('GET', '/crm/admin/fields');
        self::assertCount(1, $fields->filter('[data-testid="crm-field-new-company"][data-nowo-confirm-target="crm-field-dialog-company"]'));
        self::assertCount(1, $fields->filter('[data-testid="crm-field-new-contact"][data-nowo-confirm-target="crm-field-dialog-contact"]'));
        self::assertCount(0, $fields->filter('[data-testid="crm-fields-for"]'), 'no subject switch: one action per subject');
        self::assertSame('crm-field-dialog-contact_key', $fields->filter('dialog#crm-field-dialog-contact input[name="crm_field[key]"]')->attr('id'), 'unique ids per dialog');
        $contact = $fields->filter('dialog#crm-field-dialog-contact form')->form();
        self::assertStringEndsWith('for=contact', $contact->getUri());
        $bad = $this->client->submit($contact, ['crm_field[key]' => 'Bad Key', 'crm_field[label]' => 'X']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('{"modal":"crm-field-dialog-contact"}', $bad->filter('#nowo-ui-open-modal-on-load')->text());
        $this->client->submit($contact, ['crm_field[key]' => 'tier', 'crm_field[label]' => 'Tier']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_field WHERE subject_type = ?', ['contact']));
        $deep = $this->client->request('GET', '/crm/admin/fields?for=company&new=1');
        self::assertStringContainsString('"modal":"crm-field-dialog-company"', $deep->filter('#nowo-ui-open-modal-on-load')->text());
    }

    public function testCreateDialogKeepsTheGateAndTheCsrfToken(): void
    {
        $this->boot(['ui' => ['admin_create' => 'dialog_inline']]);
        $this->admin();
        $form = $this->client->request('GET', '/crm/admin/tags')->filter('dialog#crm-tag-dialog form')->form();
        $this->client->submit($form, ['crm_tag[name]' => 'VIP', 'crm_tag[_token]' => 'forged']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_tag'));
        $this->actAs('zoe', 'view', 'edit');
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/tags?new=1'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/fields?for=contact&new=1'));
    }

    public function testSettingsCanTakeTheFullWidth(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/admin/settings');
        self::assertCount(1, $page->filter('[data-testid="crm-settings"] .nowo-ui-form-panel form'), 'the UiKit form panel by default');

        $this->boot(['ui' => ['settings_width' => 'full']]);
        $this->admin();
        $page = $this->client->request('GET', '/crm/admin/settings');
        self::assertCount(0, $page->filter('.nowo-ui-form-panel'));
        $form = $page->filter('[data-testid="crm-settings"] [data-testid="crm-settings-form"] form');
        self::assertCount(1, $form, 'the bundle card');
        $this->client->submit($form->form(), ['crm_settings[trashDays]' => '3']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form->form(), ['crm_settings[trashDays]' => '60']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
    }
}
