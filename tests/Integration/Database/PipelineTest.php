<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelStatistics;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\PartyStageService;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\RecordPurger;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\NextActionView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageStats;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageView;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\TestCaller;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTaskPort;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function array_replace_recursive;
use function array_sum;
use function array_values;
use function is_string;
use function json_decode;
use function json_encode;
use function range;
use function str_repeat;

/**
 * The funnels (0.3.0): funnel definitions, deals (create, change, stage moves with the stage history, trash, restore,
 * purge), amounts behind `amounts_view`, keyset lists, statistics now and at a past instant, next actions, lifecycle
 * stages, and their part in GDPR export / erasure, merges, retention and the counters.
 */
final class PipelineTest extends CrmTestCase
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

    public function testFunnelDefinitions(): void
    {
        $this->boot();
        $this->admin();
        $funnels = $this->service(FunnelService::class);
        $id      = $funnels->create(FunnelKind::Deal, null, ' Sales ', ['open' => 'Lead']);
        $view    = $this->service(FunnelReader::class)->funnel($id);
        self::assertSame('Sales', $view->name);
        self::assertSame(['Lead', 'Won', 'Lost'], array_map(static fn (StageView $s): string => $s->name, $view->stages));
        self::assertSame([StageKind::Open, StageKind::Won, StageKind::Lost], array_map(static fn (StageView $s): StageKind => $s->kind, $view->stages));
        self::assertSame($view->stages[0], $view->firstOpenStage());
        self::assertNull($view->stage('nope'));
        $party = $funnels->create(FunnelKind::Party, SubjectType::Company, 'Customers');
        self::assertCount(1, $this->service(FunnelReader::class)->funnel($party)->stages);
        foreach ([[FunnelKind::Party, null], [FunnelKind::Deal, SubjectType::Company], [FunnelKind::Party, SubjectType::Deal]] as [$kind, $type]) {
            $this->refused(static fn () => $funnels->create($kind, $type, 'X'), 'subject_type');
        }
        $this->refused(static fn () => $funnels->create(FunnelKind::Deal, null, '  '), 'name');
        $this->refused(static fn () => $funnels->create(FunnelKind::Deal, null, str_repeat('x', 121)), 'name');

        $stage = $funnels->addStage($id, 'Proposal', StageKind::Open, 60, 14);
        $this->refused(static fn () => $funnels->addStage($party, 'Won', StageKind::Won, 100, null), 'kind');
        $this->refused(static fn () => $funnels->addStage($id, 'P', StageKind::Open, 101, null), 'probability');
        $this->refused(static fn () => $funnels->addStage($id, 'P', StageKind::Open, 10, 0), 'rot_days');
        $funnels->moveStage($stage, -1);
        $funnels->moveStage($stage, -1);
        $funnels->moveStage($stage, -1);
        $funnels->moveStage($stage, -1);
        $funnels->moveStage($stage, 1);
        $names = array_map(static fn (StageView $s): string => $s->name, $this->service(FunnelReader::class)->funnel($id)->stages);
        self::assertSame(['Lead', 'Proposal', 'Won', 'Lost'], $names);
        $funnels->moveStage($this->service(FunnelReader::class)->funnel($id)->stages[3]->id, 1);
        $view  = $this->service(FunnelReader::class)->funnel($id);
        $offer = $view->stage($stage);
        self::assertNotNull($offer);
        $funnels->changeStage($stage, 'Offer', 70, null, false, $offer->version);
        self::assertSame(70, $this->service(FunnelReader::class)->funnel($id)->stage($stage)?->probability);
        try {
            $funnels->changeStage($stage, 'Offer', 70, null, false, 1);
            self::fail('stale');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::SETTINGS_STALE, $e->errorCode);
        }
        $this->expectNotFound(static fn () => $funnels->changeStage('nope', 'X', 1, null, false, 1));
        $this->expectNotFound(static fn () => $funnels->moveStage('00000000-0000-4000-8000-000000000000', 1));
        $funnels->change($id, 'Sales EU', true, $this->service(FunnelReader::class)->funnel($id)->version);
        self::assertTrue($this->service(FunnelReader::class)->funnel($id)->archived);
        self::assertCount(1, $this->service(FunnelReader::class)->funnels());
        self::assertCount(2, $this->service(FunnelReader::class)->funnels(null, true));
        self::assertCount(1, $this->service(FunnelReader::class)->funnels(FunnelKind::Party));
        try {
            $funnels->change($id, 'X', false, 1);
            self::fail('stale');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::SETTINGS_STALE, $e->errorCode);
        }
        $this->expectNotFound(fn () => $this->service(FunnelReader::class)->funnel('nope'));
        $this->actAs('leo', 'view', 'edit');
        $this->expectDenied(static fn () => $funnels->create(FunnelKind::Deal, null, 'Mine'));
        self::assertContains('crm.funnel.created', $this->recorder()->actions());
    }

    public function testTheFunnelAndStageLimits(): void
    {
        $this->boot();
        $this->admin();
        $funnels = $this->service(FunnelService::class);
        for ($i = 0; $i < FunnelService::MAX_FUNNELS; ++$i) {
            $id = $funnels->create(FunnelKind::Party, SubjectType::Contact, 'F' . $i);
        }
        $this->refused(static fn () => $funnels->create(FunnelKind::Party, SubjectType::Contact, 'One more'), 'name');
        for ($i = 1; $i < FunnelService::MAX_STAGES; ++$i) {
            $funnels->addStage($id, 'S' . $i, StageKind::Open, 0, null);
        }
        $this->refused(static fn () => $funnels->addStage($id, 'One more', StageKind::Open, 0, null), 'kind');
    }

    public function testDealsMoveThroughTheirFunnel(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $acme                = $this->company('Acme');
        $ada                 = $this->contact('Ada', 'L', 'ada@x.test');
        $funnel              = $this->funnel();
        [$lead, $won, $lost] = array_map(static fn (StageView $s): string => $s->id, $funnel->stages);
        $deals               = $this->service(DealService::class);
        $this->refused(static fn () => $deals->create(new DealInput('Deal', $funnel->id)), 'company_id');
        $this->refused(static fn () => $deals->create(new DealInput(' ', $funnel->id, companyId: $acme)), 'title');
        $this->refused(static fn () => $deals->create(new DealInput('Deal', $funnel->id, companyId: '00000000-0000-4000-8000-000000000000')), 'company_id');
        $this->refused(static fn () => $deals->create(new DealInput('Deal', $funnel->id, companyId: $acme, ownerId: 'nobody')), 'owner');
        $this->conflict(static fn () => $deals->create(new DealInput('Deal', $funnel->id, $won, $acme)), ConflictException::STAGE_INVALID);
        $id = $deals->create(new DealInput('Big deal', $funnel->id, null, $acme, $ada, 'leo', new Money('12000.50', 'EUR'), 40, new DateImmutable('2026-12-31')));

        $reader = $this->service(DealReader::class);
        $deal   = $reader->deal($id);
        self::assertSame([$lead, DealStatus::Open, 'Acme', 'Ada L', 'Leo', 40, 40, '4800.2'], [$deal->stageId, $deal->status, $deal->companyLabel, $deal->contactLabel, $deal->ownerName, $deal->probability, $deal->effectiveProbability, $deal->weightedValue]);
        self::assertSame('12000.5', $deal->amount?->amount);
        self::assertSame('2026-12-31', $deal->expectedClose?->value);
        self::assertCount(1, $deal->history);
        self::assertNull($deal->history[0]->fromStageId);

        $this->clock()->modify('+3 days');
        $this->refused(static fn () => $deals->moveStage($id, $lost), 'lost_reason');
        $this->refused(static fn () => $deals->moveStage($id, $lost, 'boredom'), 'lost_reason');
        $this->refused(static fn () => $deals->moveStage($id, $lost, 'price', str_repeat('x', 501)), 'lost_note');
        $this->conflict(static fn () => $deals->moveStage($id, '00000000-0000-4000-8000-000000000000'), ConflictException::STAGE_INVALID);
        $this->conflict(static fn () => $deals->moveStage($id, $won, null, null, 99), ConflictException::STALE);
        self::assertFalse($deals->moveStage($id, $lead));
        self::assertTrue($deals->moveStage($id, $lost, 'price', ' Too expensive '));
        $deal = $reader->deal($id);
        self::assertSame([DealStatus::Lost, 'price', 'Too expensive'], [$deal->status, $deal->lostReason, $deal->lostNote]);
        self::assertNotNull($deal->closedAt);
        self::assertSame(3 * 86400, $deal->history[0]->secondsInFrom);
        self::assertTrue($deals->moveStage($id, $lead, null, null, $deal->version));
        $deal = $reader->deal($id);
        self::assertSame([DealStatus::Open, null, null, null], [$deal->status, $deal->lostReason, $deal->lostNote, $deal->closedAt]);

        $version = $deals->update($id, new DealInput('Bigger deal', amount: new Money('15000', 'USD'), fields: ['title', 'amount', 'expected_close', 'owner']), $deal->version);
        self::assertGreaterThan($deal->version, $version);
        $deal = $reader->deal($id);
        self::assertSame(['Bigger deal', '15000', 'USD', null, null], [$deal->title, $deal->amount?->amount, $deal->amount?->currency, $deal->expectedClose, $deal->ownerId]);
        self::assertSame($version, $deals->update($id, new DealInput('Bigger deal', fields: ['title']), $version));
        $version = $deals->update($id, new DealInput(contactId: $ada, probability: 90, fields: ['company_id', 'contact_id', 'probability']), $version);
        $this->refused(static fn () => $deals->update($id, new DealInput(fields: ['company_id', 'contact_id']), $version), 'company_id');
        $this->conflict(static fn () => $deals->update($id, new DealInput('X', fields: ['title']), 1), ConflictException::STALE);
        $deal = $reader->deal($id);
        self::assertSame([null, $ada, 90], [$deal->companyId, $deal->contactId, $deal->probability]);
        $version = $deals->update($id, new DealInput(companyId: $acme, expectedClose: new DateImmutable('2027-01-15'), fields: ['company_id', 'expected_close']), $deal->version);

        $this->conflict(static fn () => $deals->trash($id, 1), ConflictException::STALE);
        $deals->trash($id, $version);
        $this->expectNotFound(static fn () => $reader->deal($id));
        self::assertSame(DealStatus::Trashed, $reader->deal($id, true)->status);
        $this->conflict(static fn () => $deals->trash($id), null, true);
        $deals->restore($id);
        self::assertSame(DealStatus::Open, $reader->deal($id)->status);
        $this->conflict(static fn () => $deals->restore($id), ConflictException::STALE);
        $this->conflict(static fn () => $deals->purge($id), ConflictException::STALE);
        $deals->trash($id);
        $deals->purge($id);
        $this->expectNotFound(static fn () => $reader->deal($id, true));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_stage_change'));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE subject_type = 'deal'"));
        foreach (['crm.deal.created', 'crm.deal.stage_moved', 'crm.deal.updated', 'crm.deal.trashed', 'crm.deal.restored', 'crm.deal.purged'] as $action) {
            self::assertContains($action, $this->recorder()->actions());
        }
    }

    public function testAmountsNeedTheirPermissionAndDealsTheirOwners(): void
    {
        $this->boot(['security' => ['resource_scoping' => true]]);
        $this->admin();
        $acme   = $this->company('Acme');
        $funnel = $this->funnel();
        $deals  = $this->service(DealService::class);
        $this->expectDenied(static fn () => $deals->create(new DealInput('D', $funnel->id, companyId: $acme, amount: new Money('1', 'EUR'))));
        $id     = $deals->create(new DealInput('D', $funnel->id, companyId: $acme, ownerId: 'leo'));
        $reader = $this->service(DealReader::class);
        $deal   = $reader->deal($id);
        self::assertTrue($deal->amountsHidden);
        self::assertNull($deal->weightedValue);
        $this->expectDenied(static fn () => $deals->update($id, new DealInput(probability: 10, fields: ['probability']), $deal->version));
        $this->expectDenied(static fn () => $reader->deals(new DealQuery(sort: DealQuery::SORT_AMOUNT)));
        // Another person who may view but not this deal (a denied record check): not found, everywhere.
        $this->actAs('leo', 'view');
        $this->ctx()->denied = ['view:' . $id];
        $this->expectNotFound(static fn () => $reader->deal($id));
        self::assertSame([], $reader->deals(new DealQuery())->deals);
        $this->expectNotFound(static fn () => $deals->moveStage($id, $funnel->stages[1]->id));
        $this->ctx()->denied = ['edit:' . $id];
        $this->expectDenied(static fn () => $deals->moveStage($id, $funnel->stages[1]->id));
        $this->expectDenied(static fn () => $deals->trash($id));
        $this->ctx()->denied = [];
        self::assertCount(1, $reader->deals(new DealQuery())->deals);
        // A related record the actor may not see is hidden (flag only, no id, no label).
        $this->actAs('ana', 'view', 'edit', 'manage');
        $this->ctx()->denied = ['view:' . $acme];
        $deal                = $reader->deal($id);
        self::assertSame([null, null, true], [$deal->companyId, $deal->companyLabel, $deal->companyHidden]);
        $this->refused(static fn () => $deals->create(new DealInput('E', $funnel->id, companyId: $acme)), 'company_id');
    }

    public function testDealListsAreKeysetPagedInEveryOrder(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $acme   = $this->company('Acme');
        $funnel = $this->funnel();
        $deals  = $this->service(DealService::class);
        /** @var array<string, string> $ids */
        $ids = [];
        foreach ([['A', '10', '2026-12-01'], ['B', null, null], ['C', '30', '2026-11-30'], ['D', '20', null], ['E', null, '2027-01-01']] as [$title, $amount, $close]) {
            $this->clock()->modify('+1 second');
            $ids[$title] = $deals->create(new DealInput($title . ' 50%_!', $funnel->id, companyId: $acme, amount: $amount === null ? null : new Money($amount, 'EUR'), expectedClose: $close === null ? null : new DateImmutable($close)));
        }
        $reader = $this->service(DealReader::class);
        foreach ([DealQuery::SORT_UPDATED => ['E', 'D', 'C', 'B', 'A'], DealQuery::SORT_EXPECTED_CLOSE => ['C', 'A', 'E', 'B', 'D'], DealQuery::SORT_AMOUNT => ['C', 'D', 'A', 'B', 'E']] as $sort => $expected) {
            $seen  = [];
            $after = null;
            do {
                $page  = $reader->deals(new DealQuery(sort: $sort), $after, 2);
                $seen  = [...$seen, ...array_map(static fn (DealView $d): string => $d->title[0], $page->deals)];
                $after = $page->next;
            } while ($after !== null);
            self::assertSame($expected, $seen, $sort);
        }
        self::assertCount(5, $reader->deals(new DealQuery(text: '50%_!'))->deals);
        self::assertCount(0, $reader->deals(new DealQuery(text: '50x'))->deals);
        self::assertCount(5, $reader->deals(new DealQuery(funnelId: $funnel->id, stageIds: [$funnel->stages[0]->id]))->deals);
        self::assertCount(0, $reader->deals(new DealQuery(stageIds: []))->deals);
        self::assertCount(0, $reader->deals(new DealQuery(status: DealStatus::Won))->deals);
        self::assertCount(5, $reader->deals(new DealQuery(companyId: $acme))->deals);
        self::assertCount(0, $reader->deals(new DealQuery(ownerId: 'leo', contactId: $acme))->deals);
        self::assertCount(5, $reader->ofRecord(SubjectType::Company, $acme));
        $this->expectNotFound(static fn () => $reader->ofRecord(SubjectType::Deal, $acme));
        $deals->trash($ids['A'] ?? '');
        self::assertCount(1, $reader->deals(new DealQuery(status: DealStatus::Trashed))->deals);
        // Stale: an age limit on the stage, then an overdue next action.
        $stage = $this->service(FunnelService::class);
        $view  = $this->service(FunnelReader::class)->funnel($funnel->id);
        $stage->changeStage($view->stages[0]->id, 'Lead', 10, 2, false, $view->stages[0]->version);
        self::assertCount(0, $reader->deals(new DealQuery(staleOnly: true))->deals);
        $this->clock()->modify('+3 days');
        self::assertCount(4, $reader->deals(new DealQuery(staleOnly: true))->deals);
        self::assertTrue($reader->deal($ids['B'] ?? '')->stale);
    }

    public function testStatisticsNowAtAPastInstantAndTheFlow(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $vip                 = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $acme                = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Acme'], null, null, [$vip], roles: ['customer']));
        $other               = $this->company('Other');
        $funnel              = $this->funnel();
        [$lead, $won, $lost] = array_map(static fn (StageView $s): string => $s->id, $funnel->stages);
        $deals               = $this->service(DealService::class);
        $a                   = $deals->create(new DealInput('A', $funnel->id, companyId: $acme, ownerId: 'leo', amount: new Money('100', 'EUR')));
        $b                   = $deals->create(new DealInput('B', $funnel->id, companyId: $other, amount: new Money('50.5', 'USD'), probability: 50));
        $c                   = $deals->create(new DealInput('C', $funnel->id, companyId: $other, amount: new Money('200', 'EUR')));
        $start               = $this->clock()->now();
        $this->clock()->modify('+1 day');
        $deals->moveStage($a, $won);
        $deals->moveStage($c, $lost, 'timing');

        $stats = $this->service(FunnelStatistics::class)->stats($funnel->id, null, null, null, null, true, $start, $this->clock()->now()->modify('+1 day'));
        self::assertFalse($stats->past);
        self::assertSame([1, 1, 1], array_map(static fn (StageStats $s): int => $s->count, $stats->stages));
        self::assertSame([['currency' => 'USD', 'sum' => '50.5', 'weighted' => '25.25']], $stats->stages[0]->values);
        self::assertSame([['currency' => 'EUR', 'sum' => '100', 'weighted' => '100']], $stats->stages[1]->values);
        self::assertSame(['won' => 1, 'lost' => 1, 'reasons' => ['timing' => 1]], $stats->closed);
        self::assertSame(0.3333, $stats->stages[0]->conversion);
        self::assertNull($stats->stages[1]->conversion, 'no conversion out of a closed stage');
        self::assertSame(0.3333, $stats->winRate);
        self::assertSame(86400, $stats->stages[0]->averageSeconds);

        $past = $this->service(FunnelStatistics::class)->stats($funnel->id, $start);
        self::assertTrue($past->past);
        self::assertSame([3, 0, 0], array_map(static fn (StageStats $s): int => $s->count, $past->stages));
        self::assertNull($past->stages[0]->stale);
        self::assertCount(2, $past->stages[0]->values ?? []);

        self::assertSame([0, 1, 0], array_map(static fn (StageStats $s): int => $s->count, $this->service(FunnelStatistics::class)->stats($funnel->id, null, 'leo')->stages));
        self::assertSame([0, 1, 0], array_map(static fn (StageStats $s): int => $s->count, $this->service(FunnelStatistics::class)->stats($funnel->id, null, null, 'customer')->stages));
        self::assertSame([0, 1, 0], array_map(static fn (StageStats $s): int => $s->count, $this->service(FunnelStatistics::class)->stats($funnel->id, null, null, null, $vip)->stages));
        $csv = FunnelStatistics::csv($stats, ['stage' => '=Stage']);
        self::assertStringContainsString('"\'=Stage"', $csv);
        self::assertStringContainsString('"Lead","open","1","0","USD","50.5","25.25"', $csv);
        self::assertStringContainsString('"Lost","lost","1","0","EUR","200","0"', $csv);

        $this->refusedField(fn () => $this->service(FunnelStatistics::class)->stats($funnel->id, $this->clock()->now()->modify('+1 hour')), 'at');
        $this->refusedField(fn () => $this->service(FunnelStatistics::class)->stats($funnel->id, null, null, null, null, false, $start, $start), 'to');
        $party = $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Life');
        $this->refusedField(fn () => $this->service(FunnelStatistics::class)->stats($party), 'funnel_id');
        // Without amounts_view: counts only.
        $this->actAs('leo', 'view');
        $hidden = $this->service(FunnelStatistics::class)->stats($funnel->id);
        self::assertTrue($hidden->valuesHidden);
        self::assertNull($hidden->stages[0]->values);
        self::assertStringContainsString('"Lead","open","1","0","","",""', FunnelStatistics::csv($hidden, []));
        self::assertNotNull($b);
    }

    public function testNextActionsOnRecordsAndDeals(): void
    {
        $this->boot();
        $this->admin();
        $acme    = $this->company('Acme');
        $funnel  = $this->funnel();
        $deal    = $this->service(DealService::class)->create(new DealInput('D', $funnel->id, companyId: $acme));
        $actions = $this->service(NextActionService::class);
        $key     = str_repeat('a', 64);
        $due     = new DateTimeImmutable('2026-11-20 09:00:00', new DateTimeZone('UTC'));
        $id      = $actions->set(SubjectType::Deal, $deal, ' Call back ', $due, null, $key, null, '/crm/deals/' . $deal);
        self::assertSame($id, $actions->set(SubjectType::Deal, $deal, 'Call back', $due, null, $key), 'the same key adopts the row');
        $actions->set(SubjectType::Deal, $deal, 'Earlier', new DateTimeImmutable('2026-11-19 09:00:00', new DateTimeZone('UTC')), 'leo', str_repeat('b', 64));
        $reader = $this->service(DealReader::class);
        self::assertEquals(new DateTimeImmutable('2026-11-19 09:00:00', new DateTimeZone('UTC')), $reader->deal($deal)->nextActionAt);
        $list = $actions->list(SubjectType::Deal, $deal);
        self::assertSame(['Earlier', 'Call back'], array_map(static fn (NextActionView $a): string => $a->title, $list));
        self::assertSame(['crm', false, 'ana'], [$list[1]->taskType, $list[1]->taskHidden, $list[1]->assigneeId]);
        self::assertSame($id, $list[1]->taskId);
        $actions->complete($list[0]->id);
        $actions->complete($list[0]->id);
        self::assertEquals(new DateTimeImmutable('2026-11-20 09:00:00', new DateTimeZone('UTC')), $reader->deal($deal)->nextActionAt);
        $actions->cancel($id);
        self::assertNull($reader->deal($deal)->nextActionAt);
        self::assertSame([NextActionStatus::Done, NextActionStatus::Cancelled], array_map(static fn (NextActionView $a): NextActionStatus => $a->status, $actions->list(SubjectType::Deal, $deal)));
        self::assertSame([], $actions->list(SubjectType::Deal, $deal, true));
        $actions->set(SubjectType::Company, $acme, 'Visit', $due, null, str_repeat('c', 64));
        self::assertCount(1, $actions->list(SubjectType::Company, $acme));
        $this->refused(static fn () => $actions->set(SubjectType::Company, $acme, ' ', $due, null, str_repeat('d', 64)), 'title');
        $this->refused(static fn () => $actions->set(SubjectType::Company, $acme, 'X', $due, null, 'short'), 'dedupe_key');
        $this->refused(static fn () => $actions->set(SubjectType::Company, $acme, 'X', $due, 'nobody', str_repeat('d', 64)), 'assignee');
        $this->expectNotFound(static fn () => $actions->complete('nope'));
        $this->actAs('leo', 'view');
        $this->expectDenied(static fn () => $actions->set(SubjectType::Company, $acme, 'X', $due, null, str_repeat('e', 64)));
        self::assertContains('crm.next_action.created', $this->recorder()->actions());
        self::assertContains('crm.next_action.done', $this->recorder()->actions());
        self::assertContains('crm.next_action.cancelled', $this->recorder()->actions());
    }

    public function testLifecycleStages(): void
    {
        $this->boot();
        $this->admin();
        $acme      = $this->company('Acme');
        $ada       = $this->contact('Ada', 'L');
        $funnels   = $this->service(FunnelService::class);
        $party     = $funnels->create(FunnelKind::Party, SubjectType::Company, 'Life', ['first' => 'Lead']);
        $customer  = $funnels->addStage($party, 'Customer', StageKind::Open, 0, null);
        $lead      = $this->service(FunnelReader::class)->funnel($party)->stages[0]->id;
        $lifecycle = $this->service(PartyStageService::class);
        self::assertTrue($lifecycle->set(SubjectType::Company, $acme, $party, $lead));
        self::assertFalse($lifecycle->set(SubjectType::Company, $acme, $party, $lead));
        $this->clock()->modify('+1 hour');
        self::assertTrue($lifecycle->set(SubjectType::Company, $acme, $party, $customer));
        self::assertSame($customer, $lifecycle->stages(SubjectType::Company, $acme)[0]->stageId);
        self::assertSame(3600, $this->int("SELECT seconds_in_from FROM nowo_crm_stage_change WHERE item_type = 'company' AND from_stage_id IS NOT NULL"));
        $this->conflict(static fn () => $lifecycle->set(SubjectType::Contact, $ada, $party, $lead), ConflictException::STAGE_INVALID);
        $this->conflict(fn () => $lifecycle->set(SubjectType::Company, $acme, $this->funnel()->id, $lead), ConflictException::STAGE_INVALID);
        $this->conflict(static fn () => $lifecycle->set(SubjectType::Company, $acme, $party, 'nope'), ConflictException::STAGE_INVALID);
        self::assertContains('crm.lifecycle.changed', $this->recorder()->actions());
    }

    public function testGdprMergesRetentionAndCounters(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->limits['deals'] = 2;
        $acme                         = $this->company('Acme');
        $ada                          = $this->contact('Ada', 'L', 'ada@x.test');
        $dup                          = $this->contact('Ada', 'L');
        $funnel                       = $this->funnel();
        $deals                        = $this->service(DealService::class);
        $one                          = $deals->create(new DealInput('Engines', $funnel->id, companyId: $acme, contactId: $dup));
        $party                        = $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Contact, 'Life');
        $stage                        = $this->service(FunnelReader::class)->funnel($party)->stages[0]->id;
        $this->service(PartyStageService::class)->set(SubjectType::Contact, $dup, $party, $stage);
        $this->service(PartyStageService::class)->set(SubjectType::Contact, $ada, $party, $stage);
        $this->service(NextActionService::class)->set(SubjectType::Contact, $dup, 'Ask', new DateTimeImmutable('2026-12-01'), null, str_repeat('f', 64));
        // The merge points everything at the survivor; the survivor's own lifecycle stage wins.
        $reader = $this->service(CrmReaderInterface::class);
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $ada, $dup, [], $reader->record(SubjectType::Contact, $ada)->version, $reader->record(SubjectType::Contact, $dup)->version);
        self::assertSame($ada, $this->service(DealReader::class)->deal($one)->contactId);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_party_stage WHERE subject_id = ?', [$ada]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_party_stage WHERE subject_id = ?', [$dup]));
        $export = (string) json_encode($this->service(CrmPersonalDataInterface::class)->export($ada));
        foreach (['Engines', 'Ask', $party] as $expected) {
            self::assertStringContainsString($expected, $export);
        }
        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        self::assertNull($this->service(DealReader::class)->deal($one)->contactId);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_party_stage'));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_next_action WHERE subject_type = 'contact'"));
        $after = (string) json_encode($this->service(CrmPersonalDataInterface::class)->export($ada));
        self::assertStringNotContainsString('Ask', $after);

        // The plan limit counts deals outside the trash; the trash gives a unit back.
        $two = $deals->create(new DealInput('Two', $funnel->id, companyId: $acme));
        $this->conflict(static fn () => $deals->create(new DealInput('Three', $funnel->id, companyId: $acme)), ConflictException::LIMIT);
        $deals->trash($two);
        $three = $deals->create(new DealInput('Three', $funnel->id, companyId: $acme));
        $this->conflict(static fn () => $deals->restore($two), ConflictException::LIMIT);
        $this->connection()->executeStatement("UPDATE nowo_crm_counter SET value = 0 WHERE resource = 'deals'");
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:counters:rebuild'));
        $tester->execute([]);
        self::assertStringContainsString('acme deals: 2', $tester->getDisplay());

        // Retention: a deal trashed past trash_days is purged with its rows; a purged company unlinks its deals.
        $purger = $this->service(RecordPurger::class);
        self::assertSame(0, $purger->purge(new TenantRef('acme'), true)['deals'] ?? -1);
        $this->clock()->modify('+31 days');
        self::assertSame(1, $purger->purge(new TenantRef('acme'), true)['deals'] ?? -1);
        self::assertSame(1, $purger->purge(new TenantRef('acme'))['deals'] ?? -1);
        $this->expectNotFound(fn () => $this->service(DealReader::class)->deal($two, true));
        $this->records()->trash(SubjectType::Company, $acme, $reader->record(SubjectType::Company, $acme)->version);
        $this->clock()->modify('+31 days');
        $purger->purge(new TenantRef('acme'));
        self::assertNull($this->service(DealReader::class)->deal($three)->companyId);
        $purge = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:purge'));
        $purge->execute([]);
        self::assertStringContainsString('deals: 0', $purge->getDisplay());
    }

    public function testTheFunnelSchema(): void
    {
        $this->boot();
        $manager = $this->connection()->createSchemaManager();
        $tables  = $manager->listTableNames();
        foreach (['funnel', 'stage', 'deal', 'stage_change', 'party_stage', 'next_action'] as $table) {
            self::assertContains('nowo_crm_' . $table, $tables);
        }
        $deal = [];
        foreach ($manager->listTableIndexes('nowo_crm_deal') as $index) {
            $deal[] = $index->getName();
        }
        self::assertContains('nowo_crm_deal_keyset_updated', $deal);
        $unique = [];
        foreach ([...$manager->listTableIndexes('nowo_crm_next_action'), ...$manager->listTableIndexes('nowo_crm_party_stage')] as $index) {
            if ($index->isUnique() && !$index->isPrimary()) {
                $unique[] = $index->getName();
            }
        }
        self::assertEqualsCanonicalizing(['nowo_crm_next_action_dedupe', 'nowo_crm_party_stage_unique'], $unique);
        if ($this->platform() === 'mysql') {
            $collation = $this->text("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nowo_crm_next_action' AND COLUMN_NAME = 'dedupe_key'");
            self::assertContains($collation, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin']);
        }
    }

    public function testFunnelTablesExistOnlyWhileEnabled(): void
    {
        parent::boot();
        $this->admin();
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $class) {
            self::assertStringNotContainsString('\\Pipeline\\', $class->getName(), 'no funnel table is mapped');
        }
        self::assertFalse($this->kernel->getContainer()->has(DealService::class));
    }

    public function testServicesBoundToAnApiCaller(): void
    {
        $this->boot(['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']]);
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $caller = new TestCaller($this->service(CrmAccess::class)->caller(), new AuditOrigin('api', 'tok-1', "Agent\u{200B}\nOne"));
        $acme   = $this->company('Acme');
        $funnel = $this->service(FunnelService::class)->as($caller)->create(FunnelKind::Deal, null, 'Sales');
        $view   = $this->service(FunnelReader::class)->as($caller)->funnel($funnel);
        $deal   = $this->service(DealService::class)->as($caller)->create(new DealInput('D', $funnel, companyId: $acme));
        self::assertTrue($this->service(DealService::class)->as($caller)->moveStage($deal, $view->stages[1]->id));
        self::assertSame('api', $this->service(DealReader::class)->as($caller)->deal($deal)->history[0]->via);
        self::assertCount(3, $this->service(FunnelStatistics::class)->as($caller)->stats($funnel)->stages);
        $this->service(NextActionService::class)->as($caller)->set(SubjectType::Deal, $deal, 'Call', new DateTimeImmutable('2026-12-01'), null, str_repeat('7', 64));
        $party = $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Life');
        $stage = $this->service(FunnelReader::class)->funnel($party)->stages[0]->id;
        self::assertTrue($this->service(PartyStageService::class)->as($caller)->set(SubjectType::Company, $acme, $party, $stage));
        $origins = [];
        foreach ($this->recorder()->audit as $entry) {
            if ($entry->origin instanceof AuditOrigin) {
                $origins[$entry->action] = $entry->origin->tokenName;
            }
        }
        self::assertSame('Agent One', $origins['crm.deal.created'] ?? null);
        self::assertArrayHasKey('crm.next_action.created', $origins);
        self::assertArrayHasKey('crm.lifecycle.changed', $origins);
        $params = json_decode($this->text("SELECT params FROM nowo_crm_activity WHERE subject_type = 'deal' AND kind = 'stage_moved'"), true);
        self::assertIsArray($params);
        self::assertSame(['api', 'Agent One'], [$params['via'] ?? null, $params['token'] ?? null]);
    }

    public function testNextActionRacesInsideTheUnit(): void
    {
        $this->boot(['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']]);
        $this->admin();
        $acme    = $this->company('Acme');
        $actions = $this->service(NextActionService::class);
        $port    = $this->kernel->getContainer()->get('test.task_port');
        self::assertInstanceOf(TestTaskPort::class, $port);
        // Another request stored the same key at the same moment: the unique key refuses ours, the retry adopts it.
        $this->ctx()->races['NextAction'] = 1;
        $id                               = $actions->set(SubjectType::Company, $acme, 'Call', new DateTimeImmutable('2026-12-01'), null, str_repeat('1', 64));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        // Another request of the same key committed while our port call ran: our unit adopts its row.
        $port->onCreate = static function () use ($actions, $acme): void {
            $actions->set(SubjectType::Company, $acme, 'Mail', new DateTimeImmutable('2026-12-01'), null, str_repeat('2', 64));
        };
        $other = $actions->set(SubjectType::Company, $acme, 'Mail', new DateTimeImmutable('2026-12-01'), null, str_repeat('2', 64));
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        self::assertNotSame($id, $other);
        // A lock conflict in the unit after the host's task exists: committed (CONFLICT_002), nothing of ours stored.
        $this->ctx()->races['NextActionLock'] = 99;
        try {
            $actions->set(SubjectType::Company, $acme, 'Visit', new DateTimeImmutable('2026-12-01'), null, str_repeat('3', 64));
            self::fail('stored');
        } catch (ConcurrencyConflict $e) {
            self::assertTrue($e->committed);
        }
        $this->ctx()->races['NextActionLock'] = 0;
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        // A racing party stage: the retry moves that row.
        $party                            = $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Life');
        $stage                            = $this->service(FunnelReader::class)->funnel($party)->stages[0]->id;
        $this->ctx()->races['PartyStage'] = 1;
        self::assertTrue($this->service(PartyStageService::class)->set(SubjectType::Company, $acme, $party, $stage));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_party_stage'));
    }

    public function testTheBuiltInListStaysUncommittedOnALockConflict(): void
    {
        $this->boot();
        $this->admin();
        $acme                                 = $this->company('Acme');
        $this->ctx()->races['NextActionLock'] = 99;
        try {
            $this->service(NextActionService::class)->set(SubjectType::Company, $acme, 'Visit', new DateTimeImmutable('2026-12-01'), null, str_repeat('3', 64));
            self::fail('stored');
        } catch (ConcurrencyConflict $e) {
            self::assertFalse($e->committed);
        }
    }

    public function testTheStoreUnderResourceScoping(): void
    {
        $this->boot(['security' => ['resource_scoping' => true]]);
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $acme   = $this->company('Acme');
        $funnel = $this->funnel();
        $deals  = $this->service(DealService::class);
        $deals->create(new DealInput('Free', $funnel->id, companyId: $acme));
        $deals->create(new DealInput('Leo', $funnel->id, companyId: $acme, ownerId: 'leo', amount: new Money('5', 'EUR')));
        $store  = $this->service(PipelineStoreInterface::class);
        $tenant = new TenantRef('acme');
        $now    = $this->clock()->now();
        $count  = static fn (?array $owners): int => array_sum(array_map(static fn (array $r): int => $r['count'], $store->stageNumbers($tenant, $funnel->id, $owners === null ? null : array_values(array_map(static fn (mixed $o): string => is_string($o) ? $o : '', $owners)), [], null, [], $now)));
        self::assertSame([2, 1, 2], [$count(null), $count([]), $count(['leo'])]);
        self::assertCount(1, $store->deals($tenant, new DealQuery(), [], null, 10, [], $now));
        self::assertCount(2, $store->deals($tenant, new DealQuery(), ['leo'], null, 10, [], $now));
        self::assertSame(1, $store->stageFlow($tenant, $funnel->id, [], [])['reached'][$funnel->stages[0]->id] ?? 0);
        try {
            $store->deals($tenant, new DealQuery(), array_map('strval', range(1, 1001)), null, 10, [], $now);
            self::fail('too many owners');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        // An age limit: stale counts in the statistics.
        $view = $this->service(FunnelReader::class)->funnel($funnel->id);
        $this->service(FunnelService::class)->changeStage($view->stages[0]->id, 'Lead', 10, 1, false, $view->stages[0]->version);
        $this->clock()->modify('+2 days');
        self::assertSame(1, $this->service(FunnelStatistics::class)->stats($funnel->id)->stages[0]->stale, 'the scoped person counts the deals it sees');
        // A deal funnel without an open stage takes no new deal.
        $this->service(FunnelService::class)->changeStage($view->stages[0]->id, 'Lead', 10, null, true, $view->stages[0]->version + 1);
        self::assertNull($this->service(FunnelReader::class)->funnel($funnel->id)->firstOpenStage());
        $this->conflict(static fn () => $deals->create(new DealInput('X', $funnel->id, companyId: $acme)), ConflictException::STAGE_INVALID);
    }

    public function testDealSearchLowersTheTextInSqlWhateverTheCase(): void
    {
        $this->boot();
        $this->admin();
        $acme   = $this->company('Acme');
        $funnel = $this->funnel();
        $deals  = $this->service(DealService::class);
        $deals->create(new DealInput('Contrato École Primaire', $funnel->id, companyId: $acme));
        $deals->create(new DealInput('Other deal', $funnel->id, companyId: $acme));
        $reader = $this->service(DealReader::class);
        // Audit YN-L1: LOWER(col) LIKE LOWER(:q), so a capital in the search finds the stored text.
        foreach (['ÉCOLE', 'École', 'CONTRATO', 'contrato'] as $text) {
            self::assertCount(1, $reader->deals(new DealQuery(text: $text))->deals, $text);
        }
        self::assertCount(0, $reader->deals(new DealQuery(text: 'ÉCOLES'))->deals);
    }

    private function funnel(): FunnelView
    {
        $reader = $this->service(FunnelReader::class);

        return $reader->funnel($this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales', ['open' => 'Lead']));
    }

    /**
     * @param callable(): mixed $call
     */
    private function refused(callable $call, string $field): void
    {
        $this->refusedField($call, $field);
    }

    /**
     * @param callable(): mixed $call
     */
    private function refusedField(callable $call, string $field): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertArrayHasKey($field, $e->errors);
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function conflict(callable $call, ?string $code, bool $notFound = false): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        } catch (NotAvailableException $e) {
            self::assertTrue($notFound, 'not found');
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectNotFound(callable $call): void
    {
        try {
            $call();
            self::fail('found');
        } catch (NotAvailableException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectDenied(callable $call): void
    {
        try {
            $call();
            self::fail('allowed');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }
}
