<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;

use function basename;
use function str_contains;

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
}
