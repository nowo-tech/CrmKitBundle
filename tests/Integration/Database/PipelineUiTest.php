<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineController;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTaskPort;

use function array_replace_recursive;
use function basename;
use function str_repeat;
use function substr_count;

/**
 * The funnel pages (0.3.0): funnels administration, the deal list (keyset pages), the board, a deal's page and forms,
 * the stage move, trash / restore / purge, statistics and their CSV, and the funnels' block of the 360° page; the
 * access matrix of the pages (404 without the CRM or the funnels, 403 without the permission).
 */
final class PipelineUiTest extends CrmTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['pipeline' => ['enabled' => true]], $config);
        parent::boot($merged);
    }

    public function testFunnelAdministration(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/admin/funnels');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('[data-testid="crm-funnel-form"] form')->form(), ['crm_funnel[name]' => '', 'crm_funnel[kind]' => 'deal']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('[data-testid="crm-funnel-form"] form')->form(), ['crm_funnel[name]' => str_repeat('x', 200), 'crm_funnel[kind]' => 'deal']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('[data-testid="crm-funnel-form"] form')->form(), ['crm_funnel[name]' => 'Sales', 'crm_funnel[kind]' => 'deal']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $edit = (string) $this->client->getResponse()->headers->get('Location');
        $id   = basename($edit);
        $this->client->submit($page->filter('[data-testid="crm-funnel-form"] form')->form(), ['crm_funnel[name]' => 'Life', 'crm_funnel[kind]' => 'party_contact']);
        $this->client->submit($page->filter('[data-testid="crm-funnel-form"] form')->form(), ['crm_funnel[name]' => 'Accounts', 'crm_funnel[kind]' => 'party_company']);
        $list = $this->client->request('GET', '/crm/admin/funnels');
        foreach (['Sales', 'Life', 'Accounts'] as $name) {
            self::assertStringContainsString($name, $list->filter('[data-testid="crm-funnels"]')->text());
        }

        $page = $this->client->request('GET', $edit);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(3, $page->filter('[data-testid="crm-stage"]'));
        $this->client->submit($page->filter('[data-testid="crm-stage-form"] form')->form(), ['crm_stage[name]' => 'Proposal', 'crm_stage[kind]' => 'open', 'crm_stage[probability]' => '60', 'crm_stage[rot_days]' => '10']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $edit);
        $this->client->submit($page->filter('[data-testid="crm-stage-form"] form')->form(), ['crm_stage[name]' => 'Bad', 'crm_stage[kind]' => 'open', 'crm_stage[probability]' => '200']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $edit);
        $this->client->submit($page->filter('[data-testid="crm-stage"]')->last()->filter('form')->first()->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $edit);
        self::assertStringContainsString('Proposal', $page->filter('[data-testid="crm-stage"]')->eq(2)->text());
        $this->client->submit($page->filter('[data-testid="crm-stage"]')->eq(2)->filter('form')->eq(1)->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $edit);
        $this->client->submit($page->filter('[data-testid="crm-stage-edit"] form')->first()->form(), ['crm_stage[name]' => 'Qualified', 'crm_stage[probability]' => '20']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('[data-testid="crm-stage-edit"] form')->first()->form(), ['crm_stage[name]' => 'Stale', 'crm_stage[probability]' => '20']);
        $this->client->followRedirect();
        self::assertStringContainsString('same moment', (string) $this->client->getResponse()->getContent(), 'the form posted the old version');
        $this->client->submit($page->filter('[data-testid="crm-stage-edit"] form')->first()->form(), ['crm_stage[name]' => '', 'crm_stage[probability]' => '20']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'an invalid stage form is a flash');
        $this->client->request('POST', '/crm/admin/stages/' . $this->service(FunnelReader::class)->funnel($id)->stages[0]->id . '/move/up');
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'no CSRF token: a flash');
        $this->client->request('POST', '/crm/admin/stages/' . $this->service(FunnelReader::class)->funnel($id)->stages[0]->id);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $edit);
        $this->client->submit($page->filter('[data-testid="crm-funnel"] form')->first()->form(), ['crm_funnel[name]' => 'Sales EU', 'crm_funnel[archived]' => '1']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->service(FunnelReader::class)->funnel($id)->archived);
        $this->client->submit($page->filter('[data-testid="crm-funnel"] form')->first()->form(), ['crm_funnel[name]' => 'Old']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('[data-testid="crm-funnel"] form')->first()->form(), ['crm_funnel[name]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/funnels/00000000-0000-4000-8000-000000000000'));
        $this->actAs('leo', 'view');
        self::assertSame(403, $this->httpStatus('GET', '/crm/admin/funnels'));
        self::assertSame(403, $this->httpStatus('GET', $edit));
    }

    public function testDealPages(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L');
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals/new'), 'no deal funnel yet');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $stages = $this->service(FunnelReader::class)->funnel($funnel)->stages;

        $page = $this->client->request('GET', '/crm/deals/new?funnel=' . $funnel . '&company=' . $acme);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('[data-testid="crm-deal-form"] form')->form();
        $this->client->submit($form, ['crm_deal[title]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_deal[title]' => 'Engines', 'crm_deal[amount]' => '12,5', 'crm_deal[currency]' => 'EUR']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_deal[title]' => 'Engines', 'crm_deal[company]' => $acme, 'crm_deal[contact]' => $ada, 'crm_deal[owner]' => 'leo', 'crm_deal[amount]' => '1200.5', 'crm_deal[currency]' => 'EUR', 'crm_deal[probability]' => '30', 'crm_deal[expected_close]' => '2026-12-31']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $show = (string) $this->client->getResponse()->headers->get('Location');
        $id   = basename($show);
        $page = $this->client->request('GET', $show);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        foreach (['Engines', 'Acme', 'Ada L', 'Leo', '1200.5 EUR', '360.15 EUR', '2026-12-31', '30 %'] as $text) {
            self::assertStringContainsString($text, $page->text());
        }

        $this->client->submit($page->filter('[data-testid="crm-deal-move"] form')->form(), ['crm_stage_move[stage]' => $stages[2]->id]);
        $this->client->followRedirect();
        self::assertStringContainsString('lost', (string) $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-deal-move"] form')->form(), ['crm_stage_move[stage]' => $stages[2]->id, 'crm_stage_move[lost_reason]' => 'price', 'crm_stage_move[lost_note]' => 'Too much']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        self::assertStringContainsString('Too much', $page->text());
        self::assertCount(2, $page->filter('[data-testid="crm-stage-change"]'));
        $this->client->submit($page->filter('[data-testid="crm-deal-move"] form')->form(), ['crm_stage_move[stage]' => $stages[2]->id]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'the same stage: nothing moves');
        $this->client->request('POST', '/crm/deals/' . $id . '/stage');
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'no CSRF token: a flash');

        $edit = $this->client->request('GET', '/crm/deals/' . $id . '/edit');
        $form = $edit->filter('[data-testid="crm-deal-form"] form')->form();
        $this->client->submit($form, ['crm_deal[title]' => 'Engines 2', 'crm_deal[amount]' => '', 'crm_deal[currency]' => '']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_deal[title]' => 'Engines 3']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'the form posted the old version');
        $edit = $this->client->request('GET', '/crm/deals/' . $id . '/edit');
        $this->client->submit($edit->filter('[data-testid="crm-deal-form"] form')->form(), ['crm_deal[title]' => ' ']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->service(DealReader::class)->deal($id)->amount);

        // Next actions on the deal page.
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('[data-testid="crm-next-action-form"] form')->form(), ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => '2026-11-20T09:00']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        self::assertStringContainsString('Call', $page->filter('[data-testid="crm-next-actions"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-next-action"] form')->first()->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/crm/deals/' . $id . '/next-actions');
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'an invalid form is a flash');

        // The list, the board, the trash.
        $list = $this->client->request('GET', '/crm/deals?funnel=' . $funnel . '&status=lost&q=engines&sort=amount');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Engines 2', $list->filter('[data-testid="crm-deals"]')->text());
        $this->service(DealService::class)->create(new DealInput('Second', $funnel, companyId: $acme, amount: new Money('5', 'EUR')));
        $this->client->request('GET', '/crm/deals?per_page=1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $board = $this->client->request('GET', '/crm/funnels/' . $funnel . '/board');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $board->filter('[data-testid="crm-board-deal"]'));
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('form[action$="/trash"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(DealStatus::Trashed, $this->service(DealReader::class)->deal($id, true)->status);
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('form[action$="/restore"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('form[action$="/trash"]')->form());
        $page = $this->client->request('GET', $show);
        $this->client->submit($page->filter('form[action$="/purge"]')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->httpStatus('GET', $show));
        $this->client->request('POST', '/crm/deals/' . $id . '/trash');
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(404, $this->httpStatus('GET', '/crm/funnels/' . $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'L') . '/board'));
    }

    public function testStatisticsPagesAndTheRecordBlock(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $acme   = $this->company('Acme');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deal   = $this->service(DealService::class)->create(new DealInput('=Engines', $funnel, companyId: $acme, amount: new Money('100', 'EUR')));
        $stats  = $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(3, $stats->filter('[data-testid="crm-stats-row"]'));
        self::assertStringContainsString('100 EUR', $stats->filter('[data-testid="crm-stats-table"]')->text());
        $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats?at=2026-11-18T09:00&owner=leo&from=2026-11-01&to=2026-11-30');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats?at=2030-01-01T00:00');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'a future instant is a form error');
        $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats.csv');
        self::assertSame('text/csv; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('"Open","open","1","0","EUR","100","10"', (string) $this->client->getResponse()->getContent());
        $party = $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Accounts');
        self::assertSame(404, $this->httpStatus('GET', '/crm/funnels/' . $party . '/stats'));

        // The 360° page: deals, lifecycle stage, next actions.
        $page = $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertStringContainsString('=Engines', $page->filter('[data-testid="crm-record-deals"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-lifecycle-form"] form')->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertStringNotContainsString('Not in this funnel', $page->filter('[data-testid="crm-lifecycle"]')->text());
        $this->client->request('POST', '/crm/companies/' . $acme . '/lifecycle', ['crm_lifecycle' => ['stage' => 'x|y']]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($page->filter('[data-testid="crm-next-action-form"] form')->form(), ['crm_next_action[title]' => 'Visit', 'crm_next_action[due_at]' => '2026-11-21T10:00']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertStringContainsString('Visit', $page->filter('[data-testid="crm-next-actions"]')->text());
        $this->client->submit($page->filter('[data-testid="crm-next-action"] form')->last()->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('/crm/companies/' . $acme, $this->client->getResponse()->headers->get('Location'));
        $this->client->request('POST', '/crm/next-actions/00000000-0000-4000-8000-000000000000/done?back=nope');
        self::assertSame('/crm/deals', $this->client->getResponse()->headers->get('Location'));
        self::assertNotSame('', $deal);
    }

    public function testANewDealWithoutCompanyOrContactShowsWhy(): void
    {
        // 0.8.1 (Beacon request 2026-11-23): the service names the field company_id, the form has company; the
        // message went to the form root, which UiKit's scaffold never printed — a 422 with no visible reason.
        $this->boot();
        $this->admin();
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $page   = $this->client->request('GET', '/crm/deals/new?funnel=' . $funnel);
        $this->client->submit($page->filter('[data-testid="crm-deal-form"] form')->form(), ['crm_deal[title]' => 'Engines']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('A deal needs a company or a contact.', $html);
        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('A deal needs a company or a contact.', $crawler->filter('#crm_deal_company')->ancestors()->first()->text(), 'next to the company field');

        $deal = $this->service(DealService::class)->create(new DealInput('Z', $funnel, companyId: $this->company('Acme')));
        $edit = $this->client->request('GET', '/crm/deals/' . $deal . '/edit');
        $this->client->submit($edit->filter('[data-testid="crm-deal-form"] form')->form(), ['crm_deal[company]' => '', 'crm_deal[contact]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'A deal needs a company or a contact.'), 'once, at the company field');
    }

    public function testFormEdgesOfTheDealPages(): void
    {
        $this->boot(['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']]);
        $this->admin();
        $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $first = $this->company('A');
        for ($i = 1; $i < 100; ++$i) {
            $this->company('A' . $i);
        }
        $zeta   = $this->company('Zeta');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Contact, 'People');
        $deal = $this->service(DealService::class)->create(new DealInput('Z', $funnel, companyId: $zeta));
        // The deal's company is past the first 100 choices: still offered, so the form keeps it.
        $edit = $this->client->request('GET', '/crm/deals/' . $deal . '/edit');
        $this->client->submit($edit->filter('[data-testid="crm-deal-form"] form')->form(), ['crm_deal[title]' => 'Z2']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        // A create at the plan limit: 409 with the form.
        $this->ctx()->limits['deals'] = 1;
        $page                         = $this->client->request('GET', '/crm/deals/new');
        $this->client->submit($page->filter('[data-testid="crm-deal-form"] form')->form(), ['crm_deal[title]' => 'Two', 'crm_deal[company]' => $first]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        // A next action without a due time, then one the task service refuses: flashes.
        $show = $this->client->request('GET', '/crm/deals/' . $deal);
        $this->client->submit($show->filter('[data-testid="crm-next-action-form"] form')->form(), ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => '']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->port()->refuse = 'TOKEN_001';
        $show                 = $this->client->request('GET', '/crm/deals/' . $deal);
        $this->client->submit($show->filter('[data-testid="crm-next-action-form"] form')->form(), ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => '2026-11-20T09:00']);
        $this->client->followRedirect();
        self::assertStringContainsString('refused', (string) $this->client->getResponse()->getContent());
        self::assertSame(200, $this->httpStatus('GET', '/crm/funnels/' . $funnel . '/stats'));
        $page = $this->client->request('GET', '/crm/companies/' . $zeta);
        self::assertCount(0, $page->filter('[data-testid="crm-lifecycle"]'), 'only funnels of companies on a company');
    }

    public function testAccessMatrixOfThePages(): void
    {
        $this->boot();
        $this->admin();
        $acme   = $this->company('Acme');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deal   = $this->service(DealService::class)->create(new DealInput('Engines', $funnel, companyId: $acme));
        $pages  = ['/crm/deals', '/crm/deals/' . $deal, '/crm/funnels/' . $funnel . '/board', '/crm/funnels/' . $funnel . '/stats'];
        // view only: the pages open; edit, create and manage pages are refused.
        $this->actAs('leo', 'view');
        foreach ($pages as $path) {
            self::assertSame(200, $this->httpStatus('GET', $path), $path);
        }
        self::assertSame(403, $this->httpStatus('GET', '/crm/deals/new'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/deals/' . $deal . '/edit'));
        // A per-record deny of view hides the deal (404), never 403.
        $this->ctx()->denied = ['view:' . $deal];
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals/' . $deal));
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals/' . $deal . '/edit'));
        $this->ctx()->denied = [];
        // Nobody acting, another tenant, the CRM off: 404.
        $this->ctx()->actor = null;
        self::assertSame(401, $this->httpStatus('GET', '/crm/deals'), 'the firewall asks who is there');
        $this->actAs('ana', 'view');
        $this->ctx()->tenant = 'other';
        $this->ctx()->grant('other', 'ana', 'view');
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals/' . $deal));
        $this->ctx()->tenant          = 'acme';
        $this->ctx()->enabled['acme'] = false;
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals'));
        // The cursor of a page is sealed and bound to the list's filters (audit VS-3).
        $controller = $this->service(PipelineController::class);
        $key        = ['v' => '2026-11-18 10:00:00.000000', 'id' => $deal];
        $cursor     = $controller->cursor($key, ['q' => 'x']);
        self::assertStringNotContainsString($deal, $cursor);
        self::assertSame($key, $controller->after($cursor, ['q' => 'x']));
        self::assertSame(['v' => null, 'id' => $deal], $controller->after($controller->cursor(['v' => null, 'id' => $deal], []), []));
        self::assertNull($controller->after($cursor, ['q' => 'y']));
        self::assertNull($controller->after('not-base64!', []));
        self::assertNull($controller->after('', []));
    }

    private function port(): TestTaskPort
    {
        $port = $this->kernel->getContainer()->get('test.task_port');
        self::assertInstanceOf(TestTaskPort::class, $port);

        return $port;
    }

    public function testThePagesAreAbsentWhileTheFunnelsAreOff(): void
    {
        parent::boot();
        $this->admin();
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/funnels'));
        $page = $this->client->request('GET', '/crm/companies/' . $this->company('Acme'));
        self::assertCount(0, $page->filter('[data-testid="crm-record-deals"]'));
    }
}
