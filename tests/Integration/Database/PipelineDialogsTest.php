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
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineDialogs;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTaskPort;
use Symfony\Component\DomCrawler\Crawler;

use function array_replace_recursive;
use function basename;
use function count;

/**
 * 0.9.0 (Beacon request 2026-11-23, owner rule D-20261123-01 "every dialog loads on demand"): with
 * `ui.pipeline_forms: dialog` the funnel pages render no form they have not opened. Move to a stage, a next action, the
 * lifecycle stage, a new funnel, a new / edited stage and the trash / purge confirm of a deal are each their own page,
 * opened by a real link into one remote form dialog per page (fragment with `X-Nowo-Dialog: 1`, 422 in place, 204 +
 * `X-Nowo-Redirect`), with the access checks, tenant, CSRF and version checks of the inline forms.
 */
final class PipelineDialogsTest extends CrmTestCase
{
    private const DIALOG = ['HTTP_X_NOWO_DIALOG' => '1'];

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port'], 'ui' => ['pipeline_forms' => 'dialog']], $config);
        parent::boot($merged);
    }

    public function testInlineStaysTheDefault(): void
    {
        parent::boot(['pipeline' => ['enabled' => true]]);
        $this->admin();
        [$deal] = $this->deal();
        $page   = $this->client->request('GET', '/crm/deals/' . $deal);
        self::assertCount(1, $page->filter('[data-testid="crm-deal-move"] form[name="crm_stage_move"]'), 'the 0.8 form in the page');
        self::assertCount(1, $page->filter('form[name="crm_next_action"]'));
        self::assertCount(0, $page->filter('dialog#' . PipelineDialogs::DIALOG));
        // The forms' own pages exist anyway (a deep link), as full pages: the fragment is for the dialog mode only.
        $this->client->request('GET', '/crm/deals/' . $deal . '/stage', [], [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
        // An inline POST keeps its 0.8 answer: a flash and the 303 back to the deal.
        $this->client->request('POST', '/crm/deals/' . $deal . '/stage');
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $funnels = $this->client->request('GET', '/crm/admin/funnels');
        self::assertCount(1, $funnels->filter('[data-testid="crm-funnel-form"] form'));
        self::assertCount(0, $funnels->filter('[data-testid="crm-funnel-new"]'));
    }

    public function testTheDealPageOpensItsFormsOnDemand(): void
    {
        $this->boot();
        $this->admin();
        [$deal, $funnel] = $this->deal();
        $page            = $this->client->request('GET', '/crm/deals/' . $deal);
        self::assertCount(0, $page->filter('form[name="crm_stage_move"]'), 'no move form in the page');
        self::assertCount(0, $page->filter('form[name="crm_next_action"]'), 'no next action form in the page');
        self::assertCount(0, $page->filter('dialog[data-nowo-confirm-dialog], .nowo-ui-confirm'), 'no confirm dialog in the page');
        self::assertCount(1, $page->filter('dialog#' . PipelineDialogs::DIALOG), 'one empty shell');
        $this->assertOpener($page, 'crm-deal-move-open', '/crm/deals/' . $deal . '/stage', 'Move to a stage');
        $this->assertOpener($page, 'crm-next-action-open', '/crm/deals/' . $deal . '/next-actions', 'Add a next action');
        $this->assertOpener($page, 'crm-deal-trash', '/crm/deals/' . $deal . '/trash', 'Move to trash');

        // Move: the fragment, 422 in place, a conflict in place, 204 once saved.
        $fragment = $this->fragment('/crm/deals/' . $deal . '/stage', 'crm-deal-move-dialog-form');
        $form     = $fragment->filter('form[name="crm_stage_move"]');
        $stages   = $this->service(FunnelReader::class)->funnel($funnel)->stages;
        $this->client->submit($form->form(), ['crm_stage_move[stage]' => $stages[2]->id], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'a lost stage needs a reason');
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('reason', (string) $this->client->getResponse()->getContent());
        $this->service(DealService::class)->moveStage($deal, $stages[1]->id);
        $this->client->submit($form->form(), ['crm_stage_move[stage]' => $stages[0]->id], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'the form posted the old version');
        self::assertStringContainsString('same moment', (string) $this->client->getResponse()->getContent());
        $fragment = $this->fragment('/crm/deals/' . $deal . '/stage', 'crm-deal-move-dialog-form');
        $this->client->submit($fragment->filter('form[name="crm_stage_move"]')->form(), ['crm_stage_move[stage]' => $stages[0]->id], self::DIALOG);
        $this->assertSaved('/crm/deals/' . $deal);
        self::assertSame($stages[0]->id, $this->service(DealReader::class)->deal($deal)->stageId);

        // The no-JS page: a full page, 409 on a stale version, 303 once saved.
        $full = $this->client->request('GET', '/crm/deals/' . $deal . '/stage');
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertContains('X-Nowo-Dialog', $this->client->getResponse()->getVary());
        $this->service(DealService::class)->moveStage($deal, $stages[1]->id);
        $this->client->submit($full->filter('[data-testid="crm-deal-move"] form')->form(), ['crm_stage_move[stage]' => $stages[0]->id]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('same moment', (string) $this->client->getResponse()->getContent());
        $full = $this->client->request('GET', '/crm/deals/' . $deal . '/stage');
        $this->client->submit($full->filter('[data-testid="crm-deal-move"] form')->form(), ['crm_stage_move[stage]' => $stages[0]->id]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());

        // A next action: 422 without a title, then 204; the task service refusing it stays in the dialog.
        $fragment = $this->fragment('/crm/deals/' . $deal . '/next-actions', 'crm-next-action-dialog-form');
        $form     = $fragment->filter('form[name="crm_next_action"]')->form();
        $this->client->submit($form, ['crm_next_action[title]' => ''], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => ''], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'a due time is required');
        $this->port()->refuse = 'TOKEN_001';
        $this->client->submit($form, ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => '2026-11-20T09:00'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('refused', (string) $this->client->getResponse()->getContent());
        $this->port()->refuse = null;
        $this->client->submit($form, ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => '2026-11-20T09:00'], self::DIALOG);
        $this->assertSaved('/crm/deals/' . $deal);
        self::assertStringContainsString('Call', $this->client->request('GET', '/crm/deals/' . $deal)->filter('[data-testid="crm-next-actions"]')->text());

        // Trash: the question and a CSRF post, 422 without the token, then 204 to the deals.
        $fragment = $this->fragment('/crm/deals/' . $deal . '/trash', 'crm-deal-trash-dialog-form');
        self::assertStringContainsString('Move this deal to the trash?', $fragment->filter('[data-testid="crm-deal-trash-dialog-form-intro"]')->text());
        self::assertNotNull($fragment->filter('[data-testid="crm-deal-trash-dialog-form-cancel"]')->attr('autofocus'), 'a destructive confirm focuses Cancel first');
        $this->client->request('POST', '/crm/deals/' . $deal . '/trash', [], [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'no CSRF token');
        $this->client->submit($fragment->filter('form')->form(), [], self::DIALOG);
        $this->assertSaved('/crm/deals');
        self::assertSame(DealStatus::Trashed, $this->service(DealReader::class)->deal($deal, true)->status);

        // The trashed deal: restore stays a button, purge is its own page (404 while not in the trash).
        $page = $this->client->request('GET', '/crm/deals/' . $deal);
        $this->assertOpener($page, 'crm-deal-purge', '/crm/deals/' . $deal . '/purge', 'Delete for good');
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals/' . $deal . '/trash'), 'already in the trash');
        $fragment = $this->fragment('/crm/deals/' . $deal . '/purge', 'crm-deal-purge-dialog-form');
        $this->client->submit($fragment->filter('form')->form(), [], self::DIALOG);
        $this->assertSaved('/crm/deals?status=trashed');
        [$other] = $this->deal('Other');
        self::assertSame(404, $this->httpStatus('GET', '/crm/deals/' . $other . '/purge'), 'not in the trash');
    }

    public function testTheRecordPageOpensItsFormsOnDemand(): void
    {
        $this->boot();
        $this->admin();
        $acme     = $this->company('Acme');
        $funnels  = $this->service(FunnelService::class);
        $accounts = $funnels->create(FunnelKind::Party, SubjectType::Company, 'Accounts');
        $funnels->addStage($accounts, 'Customer', StageKind::Open, 50, null);
        $page = $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertCount(0, $page->filter('form[name="crm_lifecycle"], form[name="crm_next_action"]'));
        $this->assertOpener($page, 'crm-lifecycle-open', '/crm/companies/' . $acme . '/lifecycle', 'Set stage');
        $this->assertOpener($page, 'crm-next-action-open', '/crm/companies/' . $acme . '/next-actions', 'Add a next action');

        $fragment = $this->fragment('/crm/companies/' . $acme . '/lifecycle', 'crm-lifecycle-dialog-form');
        $token    = (string) $fragment->filter('input[name="crm_lifecycle[_token]"]')->attr('value');
        $this->client->request('POST', '/crm/companies/' . $acme . '/lifecycle', ['crm_lifecycle' => ['stage' => 'x|y', '_token' => $token]], [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'not a choice');
        $this->client->submit($fragment->filter('form')->form(), [], self::DIALOG);
        $this->assertSaved('/crm/companies/' . $acme);
        self::assertStringNotContainsString('Not in this funnel', $this->client->request('GET', '/crm/companies/' . $acme)->filter('[data-testid="crm-lifecycle"]')->text());
        // The stage archived meanwhile: the refusal stays in the dialog.
        $fragment = $this->fragment('/crm/companies/' . $acme . '/lifecycle', 'crm-lifecycle-dialog-form');
        $form     = $fragment->filter('form')->form();
        $stage    = $this->service(FunnelReader::class)->funnels(FunnelKind::Party)[0]->stages[0];
        $funnels->changeStage($stage->id, $stage->name, $stage->probability, $stage->rotDays, true, $stage->version);
        $this->client->submit($form, [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $fragment = $this->fragment('/crm/companies/' . $acme . '/next-actions', 'crm-next-action-dialog-form');
        $this->client->submit($fragment->filter('form')->form(), ['crm_next_action[title]' => 'Visit', 'crm_next_action[due_at]' => '2026-11-21T10:00'], self::DIALOG);
        $this->assertSaved('/crm/companies/' . $acme);

        // The company moved to the trash after the dialog opened: both submits answer 404, as a trashed deal's (ZAG-1).
        $lifecycle = $this->fragment('/crm/companies/' . $acme . '/lifecycle', 'crm-lifecycle-dialog-form')->filter('form')->form();
        $action    = $this->fragment('/crm/companies/' . $acme . '/next-actions', 'crm-next-action-dialog-form')->filter('form')->form();
        $tasks     = count($this->port()->tasks);
        $this->records()->trash(SubjectType::Company, $acme, $this->service(CrmReaderInterface::class)->record(SubjectType::Company, $acme)->version);
        $this->client->submit($lifecycle, [], self::DIALOG);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->submit($action, ['crm_next_action[title]' => 'Late', 'crm_next_action[due_at]' => '2026-11-21T10:00'], self::DIALOG);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertCount($tasks, $this->port()->tasks, 'no task created');

        // A contact without a lifecycle funnel of its type: no opener, no page.
        $ada  = $this->contact('Ada', 'L');
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertCount(0, $page->filter('[data-testid="crm-lifecycle-open"]'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/lifecycle'));
    }

    public function testTheFunnelPagesOpenTheirFormsOnDemand(): void
    {
        $this->boot();
        $this->admin();
        $list = $this->client->request('GET', '/crm/admin/funnels');
        self::assertCount(0, $list->filter('form[name="crm_funnel"]'), 'no create form under the list');
        $this->assertOpener($list, 'crm-funnel-new', '/crm/admin/funnels/new', 'New funnel');
        $fragment = $this->fragment('/crm/admin/funnels/new', 'crm-funnel-new-dialog-form');
        $form     = $fragment->filter('form')->form();
        $this->client->submit($form, ['crm_funnel[name]' => ''], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_funnel[name]' => 'Sales', 'crm_funnel[kind]' => 'deal'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $funnel = basename((string) $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertSame('Sales', $this->service(FunnelReader::class)->funnel($funnel)->name);

        $page = $this->client->request('GET', '/crm/admin/funnels/' . $funnel);
        self::assertCount(0, $page->filter('form[name="crm_stage"]'), 'no stage form in the page');
        self::assertCount(1, $page->filter('form[name="crm_funnel"]'), 'the page form (rename / archive) stays');
        self::assertCount(3, $page->filter('[data-testid="crm-stage-edit-open"]'), 'one link per stage');
        $this->assertOpener($page, 'crm-stage-new', '/crm/admin/funnels/' . $funnel . '/stages/new', 'New stage');

        $fragment = $this->fragment('/crm/admin/funnels/' . $funnel . '/stages/new', 'crm-stage-new-dialog-form');
        $form     = $fragment->filter('form')->form();
        $this->client->submit($form, ['crm_stage[name]' => 'Proposal', 'crm_stage[probability]' => '200'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_stage[name]' => 'Proposal', 'crm_stage[kind]' => 'open', 'crm_stage[probability]' => '60'], self::DIALOG);
        $this->assertSaved('/crm/admin/funnels/' . $funnel);
        self::assertCount(4, $this->service(FunnelReader::class)->funnel($funnel)->stages);

        $stage = $this->service(FunnelReader::class)->funnel($funnel)->stages[0];
        $edit  = $page->filter('[data-testid="crm-stage-edit-open"]')->first();
        self::assertSame('/crm/admin/stages/' . $stage->id . '?funnel=' . $funnel, $edit->attr('href'));
        self::assertSame('Edit stage ' . $stage->name, $edit->attr('data-nowo-ui-dialog-title'));
        $fragment = $this->fragment($edit->attr('href'), 'crm-stage-edit-dialog-form');
        $form     = $fragment->filter('form')->form();
        $this->client->submit($form, ['crm_stage[name]' => ''], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_stage[name]' => 'Qualified', 'crm_stage[probability]' => '20'], self::DIALOG);
        $this->assertSaved('/crm/admin/funnels/' . $funnel);
        $this->client->submit($form, ['crm_stage[name]' => 'Stale', 'crm_stage[probability]' => '20'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'the form posted the old version');
        self::assertStringContainsString('same moment', (string) $this->client->getResponse()->getContent());
        $full = $this->client->request('GET', $edit->attr('href'));
        $this->service(FunnelService::class)->changeStage($stage->id, 'Moved', 20, null, false, $this->service(FunnelReader::class)->funnel($funnel)->stages[0]->version);
        $this->client->submit($full->filter('[data-testid="crm-stage-edit"] form')->form(), ['crm_stage[name]' => 'Again']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'the page answers the conflict with 409');
        $full = $this->client->request('GET', $edit->attr('href'));
        $this->client->submit($full->filter('[data-testid="crm-stage-edit"] form')->form(), ['crm_stage[name]' => '']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        // A stage is found through its funnel: without it, or through another funnel, 404.
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/stages/' . $stage->id));
        $other = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Other');
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/stages/' . $stage->id . '?funnel=' . $other));
        self::assertSame(404, $this->httpStatus('GET', '/crm/admin/funnels/00000000-0000-4000-8000-000000000000/stages/new'));
    }

    public function testTheFragmentsKeepTheAccessChecks(): void
    {
        $this->boot();
        $this->admin();
        [$deal, $funnel] = $this->deal();
        $acme            = $this->company('Beta');
        $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Accounts');
        $stage = $this->service(FunnelReader::class)->funnel($funnel)->stages[0]->id;
        $edits = ['/crm/deals/' . $deal . '/stage', '/crm/deals/' . $deal . '/next-actions', '/crm/deals/' . $deal . '/trash', '/crm/companies/' . $acme . '/lifecycle', '/crm/companies/' . $acme . '/next-actions'];
        $admin = ['/crm/admin/funnels/new', '/crm/admin/funnels/' . $funnel . '/stages/new', '/crm/admin/stages/' . $stage . '?funnel=' . $funnel];
        // view only: 403 on every form, as on its POST; the deal page shows no opener.
        $this->actAs('leo', 'view');
        foreach ([...$edits, ...$admin] as $path) {
            self::assertSame(403, $this->dialogStatus('GET', $path), $path);
            self::assertSame(403, $this->dialogStatus('POST', $path), 'POST ' . $path);
        }
        $page = $this->client->request('GET', '/crm/deals/' . $deal);
        self::assertCount(0, $page->filter('[data-nowo-ui-dialog-open]'));
        self::assertCount(0, $page->filter('dialog#' . PipelineDialogs::DIALOG), 'no opener, no shell');
        // A per-record deny of view hides the deal (404), never 403; another tenant: 404.
        $this->admin();
        $this->ctx()->denied = ['view:' . $deal];
        self::assertSame(404, $this->dialogStatus('GET', '/crm/deals/' . $deal . '/stage'));
        self::assertSame(404, $this->dialogStatus('GET', '/crm/deals/' . $deal . '/next-actions'));
        $this->ctx()->denied = [];
        $this->ctx()->tenant = 'other';
        $this->ctx()->grant('other', 'ana', 'view', 'edit', 'manage');
        foreach ([...$edits, '/crm/admin/funnels/' . $funnel . '/stages/new', '/crm/admin/stages/' . $stage . '?funnel=' . $funnel] as $path) {
            self::assertSame(404, $this->dialogStatus('GET', $path), $path);
        }
    }

    public function testATrashedOrErasedRecordHasNoFormPages(): void
    {
        // ZAG-1: the record's page offers no next action / lifecycle form unless it is active; the forms' own pages and
        // their dialog fragments answer 404 then (GET and POST), like a deal in the trash, never a form that cannot save.
        $this->boot();
        $this->admin();
        $funnels = $this->service(FunnelService::class);
        foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
            $funnels->addStage($funnels->create(FunnelKind::Party, $type, 'Life ' . $type->value), 'Customer', StageKind::Open, 50, null);
        }
        $acme  = $this->company('Acme');
        $ada   = $this->contact('Ada', 'L');
        $forms = static fn (string $path): array => [$path . '/lifecycle', $path . '/next-actions'];
        foreach ([...$forms('/crm/companies/' . $acme), ...$forms('/crm/contacts/' . $ada)] as $path) {
            self::assertSame(200, $this->httpStatus('GET', $path), 'active: ' . $path);
        }
        $this->records()->trash(SubjectType::Company, $acme, $this->service(CrmReaderInterface::class)->record(SubjectType::Company, $acme)->version);
        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        foreach ([...$forms('/crm/companies/' . $acme), ...$forms('/crm/contacts/' . $ada)] as $path) {
            self::assertSame(404, $this->httpStatus('GET', $path), 'page: ' . $path);
            self::assertSame(404, $this->dialogStatus('GET', $path), 'dialog: ' . $path);
            self::assertSame(404, $this->dialogStatus('POST', $path), 'dialog POST: ' . $path);
        }
        self::assertSame([], $this->port()->tasks, 'no task created');
    }

    /**
     * A deal funnel and a deal in it: [deal id, funnel id].
     *
     * @return array{string, string}
     */
    private function deal(string $title = 'Engines'): array
    {
        $funnels = $this->service(FunnelReader::class)->funnels(FunnelKind::Deal);
        $funnel  = $funnels === [] ? $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales') : $funnels[0]->id;

        return [$this->service(DealService::class)->create(new DealInput($title, $funnel, companyId: $this->company('Acme ' . $title))), $funnel];
    }

    private function assertOpener(Crawler $page, string $testid, string $href, string $title): void
    {
        $opener = $page->filter('[data-testid="' . $testid . '"]');
        self::assertCount(1, $opener, $testid);
        self::assertSame('a', $opener->nodeName(), $testid . ': a real link (the page without JavaScript)');
        self::assertSame($href, $opener->attr('href'));
        self::assertSame($href, $opener->attr('data-nowo-ui-dialog-src'));
        self::assertSame(PipelineDialogs::DIALOG, $opener->attr('data-nowo-ui-dialog-open'));
        self::assertSame($title, $opener->attr('data-nowo-ui-dialog-title'));
        self::assertSame('dialog', $opener->attr('aria-haspopup'));
    }

    private function fragment(string $path, string $testid): Crawler
    {
        $fragment = $this->client->request('GET', $path, [], [], self::DIALOG);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), $path);
        self::assertContains('X-Nowo-Dialog', $response->getVary());
        self::assertStringNotContainsString('<html', (string) $response->getContent(), $path . ': a fragment');
        $form = $fragment->filter('[data-testid="' . $testid . '"] form');
        self::assertCount(1, $form, $testid);
        self::assertSame(PipelineDialogs::DIALOG . '-title', $form->attr('aria-labelledby'));
        self::assertCount(1, $form->filter('[data-nowo-ui-dialog-actions] button[type="submit"]'));

        return $fragment;
    }

    private function assertSaved(string $location): void
    {
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($location, $response->headers->get('X-Nowo-Redirect'));
    }

    private function dialogStatus(string $method, string $path): int
    {
        $this->client->request($method, $path, [], [], self::DIALOG);

        return $this->client->getResponse()->getStatusCode();
    }

    private function port(): TestTaskPort
    {
        $port = $this->kernel->getContainer()->get('test.task_port');
        self::assertInstanceOf(TestTaskPort::class, $port);

        return $port;
    }
}
