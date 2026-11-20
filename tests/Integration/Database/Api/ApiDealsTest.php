<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiFunnelStatsQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiNextActionDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiPageRequest;
use Nowo\CrmKitBundle\Api\Input\CrmApiStageMove;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiList;
use Nowo\CrmKitBundle\Api\Read\CrmApiPage;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Deals, funnels, statistics and next actions through the API (0.3.0).
 */
final class ApiDealsTest extends ApiTestCase
{
    public function testDealsFromCreateToTrash(): void
    {
        $this->bootApi();
        $p                   = $this->principal();
        $t                   = $this->acme();
        $acme                = $this->company('Acme');
        $funnel              = $this->dealFunnel();
        [$lead, $won, $lost] = [$funnel->stages[0]->id, $funnel->stages[1]->id, $funnel->stages[2]->id];
        $draft               = CrmApiDealDraft::fromArray(['title' => 'Big', 'funnel_id' => $funnel->id, 'stage_id' => $lead, 'company_id' => $acme, 'amount' => '1000.5', 'currency' => 'EUR', 'probability' => 40, 'expected_close' => '2026-12-31']);
        $made                = $this->deals()->create($p, $t, $this->key('d1'), $draft);
        self::assertSame([201, false], [$made->status, $made->replayed]);
        self::assertInstanceOf(CrmApiItem::class, $made->result);
        $id = $made->result->fact('id');
        self::assertIsString($id);
        self::assertSame([['amount' => '1000.5', 'currency' => 'EUR'], '400.2', 'Big'], [$made->result->fact('amount'), $made->result->fact('weighted_value'), $made->result->text['title']]);
        self::assertTrue($this->deals()->create($p, $t, $this->key('d1'), $draft)->replayed);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_deal'));
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->deals()->create($this->principal(amounts: false), $t, $this->key(), $draft));
        $this->refused(CrmApiCode::STAGE, fn (): CrmApiWritten => $this->deals()->create($p, $t, $this->key(), new CrmApiDealDraft('X', new RecordId($funnel->id), new RecordId($won), new RecordId($acme))));
        $this->refused(CrmApiCode::INPUT_REFUSED, fn (): CrmApiWritten => $this->deals()->create($p, $t, $this->key(), new CrmApiDealDraft('X', new RecordId($funnel->id), new RecordId($lead))));

        // Reads: amounts only with the tick; search and its cursor; funnels; stats.
        $plain = $this->deals()->deal($this->principal(amounts: false), $t, new RecordId($id));
        self::assertSame([null, true], [$plain->fact('amount'), $plain->fact('amounts_hidden')]);
        $this->deals()->create($p, $t, $this->key(), new CrmApiDealDraft('Small', new RecordId($funnel->id), new RecordId($lead), new RecordId($acme)));
        $page = $this->deals()->search($p, $t, new CrmApiDealQuery(page: new CrmApiPageRequest(null, 1)));
        self::assertCount(1, $page->items);
        self::assertNotNull($page->nextCursor);
        self::assertCount(1, $this->deals()->search($p, $t, new CrmApiDealQuery(page: new CrmApiPageRequest(new CrmApiCursor($page->nextCursor), 1)))->items);
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiPage => $this->deals()->search($this->principal(amounts: false), $t, new CrmApiDealQuery(sort: 'amount')));
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiDealQuery => new CrmApiDealQuery(sort: 'name'));
        self::assertCount(2, $this->deals()->search($p, $t, new CrmApiDealQuery(sort: 'amount'))->items);
        self::assertCount(1, $this->deals()->funnels($p, $t)->items);
        $stats = $this->deals()->stats($p, $t, new CrmApiFunnelStatsQuery(new RecordId($funnel->id)));
        self::assertFalse($stats->fact('values_hidden'));
        self::assertTrue($this->deals()->stats($this->principal(amounts: false), $t, new CrmApiFunnelStatsQuery(new RecordId($funnel->id)))->fact('values_hidden'));
        $party = $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Customers');
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $this->deals()->stats($p, $t, new CrmApiFunnelStatsQuery(new RecordId($party))));

        // Update with a base version; stage moves; trash.
        $this->refused(CrmApiCode::STALE, fn (): CrmApiWritten => $this->deals()->update($p, $t, $this->key(), new RecordId($id), new RecordVersion(9), CrmApiDealChanges::fromArray(['title' => 'Bigger'])));
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->deals()->update($this->principal(amounts: false), $t, $this->key(), new RecordId($id), new RecordVersion(1), CrmApiDealChanges::fromArray(['probability' => 10])));
        $updated = $this->deals()->update($p, $t, $this->key('u'), new RecordId($id), new RecordVersion(1), CrmApiDealChanges::fromArray(['title' => 'Bigger', 'owner' => null]));
        self::assertTrue($updated->changed);
        self::assertTrue($this->deals()->update($p, $t, $this->key('u'), new RecordId($id), new RecordVersion(1), CrmApiDealChanges::fromArray(['title' => 'Bigger', 'owner' => null]))->replayed);
        $this->refused(CrmApiCode::LOST_REASON, fn (): CrmApiWritten => $this->deals()->moveStage($p, $t, $this->key(), new RecordId($id), CrmApiStageMove::fromArray(['stage_id' => $lost]), null));
        $moved = $this->deals()->moveStage($p, $t, $this->key('m'), new RecordId($id), CrmApiStageMove::fromArray(['stage_id' => $lost, 'lost_reason' => 'price', 'lost_note' => 'Too much']), null);
        self::assertTrue($moved->changed);
        self::assertTrue($this->deals()->moveStage($p, $t, $this->key('m'), new RecordId($id), CrmApiStageMove::fromArray(['stage_id' => $lost, 'lost_reason' => 'price', 'lost_note' => 'Too much']), null)->replayed);
        self::assertFalse($this->deals()->moveStage($p, $t, $this->key(), new RecordId($id), new CrmApiStageMove(new RecordId($lost)), null)->changed);
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiStageMove => CrmApiStageMove::fromArray(['stage_id' => $lost, 'lost_reason' => 'Bad Reason']));

        // Next actions on the deal through the built-in port.
        $next = $this->nextActions()->set($p, $t, $this->key('na'), CrmApiNextActionDraft::fromArray(SubjectType::Deal, new RecordId($id), ['title' => 'Call', 'due_at' => '2026-11-25T09:00:00Z']));
        self::assertSame(201, $next->status);
        self::assertInstanceOf(CrmApiItem::class, $next->result);
        self::assertSame('Call', $next->result->text['title']);
        self::assertTrue($this->nextActions()->set($p, $t, $this->key('na'), CrmApiNextActionDraft::fromArray(SubjectType::Deal, new RecordId($id), ['title' => 'Call', 'due_at' => '2026-11-25T09:00:00Z']))->replayed);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        $this->nextActions()->set($p, $t, $this->key(), new CrmApiNextActionDraft(SubjectType::Company, new RecordId($acme), 'Visit', new DateTimeImmutable('2026-11-26 10:00:00')));
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->nextActions()->set($this->principal(CrmApiLevel::View), $t, $this->key(), new CrmApiNextActionDraft(SubjectType::Company, new RecordId($acme), 'Visit', new DateTimeImmutable())));
        $this->refused(CrmApiCode::INPUT_MISSING, static fn (): CrmApiNextActionDraft => CrmApiNextActionDraft::fromArray(SubjectType::Deal, new RecordId($acme), ['title' => 'x']));
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiNextActionDraft => CrmApiNextActionDraft::fromArray(SubjectType::Deal, new RecordId($acme), ['title' => 'x', 'due_at' => 'tomorrow']));
        $timeline = $this->reads()->timeline($p, $t, SubjectType::Deal, new RecordId($id), new CrmApiPageRequest());
        self::assertNotSame([], $timeline->items);

        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->deals()->trash($this->principal(destructive: false), $t, $this->key(), new RecordId($id), null));
        $trash = $this->deals()->trash($p, $t, $this->key('tr'), new RecordId($id), null);
        self::assertFalse($trash->replayed);
        self::assertTrue($this->deals()->trash($p, $t, $this->key('tr'), new RecordId($id), null)->replayed);
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $this->deals()->deal($p, $t, new RecordId($id)));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $this->deals()->deal($p, new TenantRef('beta'), new RecordId($id)));
        self::assertContains('crm.deal.trashed', $this->recorder()->actions());
    }

    public function testWithoutTheFunnelsDealOperationsAreOutOfReach(): void
    {
        $this->bootApi(['pipeline' => ['enabled' => false]]);
        $p = $this->principal();
        $t = $this->acme();
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiList => $this->deals()->funnels($p, $t));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiPage => $this->deals()->search($p, $t, new CrmApiDealQuery()));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiWritten => $this->nextActions()->set($p, $t, $this->key(), new CrmApiNextActionDraft(SubjectType::Company, new RecordId($this->company('A')), 'x', new DateTimeImmutable())));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiPage => $this->reads()->timeline($p, $t, SubjectType::Deal, new RecordId('00000000-0000-4000-8000-000000000000'), new CrmApiPageRequest()));
        $record = $this->reads()->company($p, $t, new RecordId($this->company('B')));
        self::assertSame([null, false], [$record->fact('amounts'), $record->fact('amounts_hidden')]);
    }

    private function dealFunnel(): FunnelView
    {
        $id = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales', ['open' => 'Lead']);

        return $this->service(FunnelReader::class)->funnel($id);
    }
}
