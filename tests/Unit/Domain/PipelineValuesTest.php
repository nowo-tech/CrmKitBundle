<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\Pipeline\Decimal;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\PartyStage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\LostReasonKey;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The funnels' value objects, enums and entities (0.3.0).
 */
final class PipelineValuesTest extends TestCase
{
    public function testMoney(): void
    {
        self::assertSame(['12.5', 'EUR'], [(new Money('12.5000', 'EUR'))->amount, (new Money('12.5000', 'EUR'))->currency]);
        self::assertSame('0', (new Money('-0.00', 'EUR'))->amount);
        self::assertSame('-3.1', (new Money('-3.10', 'EUR'))->amount);
        self::assertSame('10', Money::normalise('10'));
        self::assertSame('0.5', Money::normalise('00.5'));
        self::assertTrue((new Money('1', 'EUR'))->equals(new Money('1.0', 'EUR')));
        self::assertFalse((new Money('1', 'EUR'))->equals(new Money('1', 'USD')));
        self::assertTrue(Money::isValid('99999999999999.9999', 'USD'));
        foreach ([['1,5', 'EUR'], ['1.12345', 'EUR'], ['100000000000000', 'EUR'], ['1', 'eur'], ['01', 'EUR'], [' 1', 'EUR']] as [$amount, $currency]) {
            self::assertFalse(Money::isValid($amount, $currency));
        }
        $this->expectException(InvalidArgumentException::class);
        new Money('x', 'EUR');
    }

    /**
     * @return iterable<array{string, int, string}>
     */
    public static function percents(): iterable
    {
        yield ['100', 25, '25'];
        yield ['12000.5', 40, '4800.2'];
        yield ['0.0001', 1, '0.000001'];
        yield ['-10', 50, '-5'];
        yield ['99999999999999.9999', 100, '99999999999999.9999'];
        yield ['5', 0, '0'];
    }

    #[DataProvider('percents')]
    public function testDecimalPercent(string $amount, int $percent, string $expected): void
    {
        self::assertSame($expected, Decimal::percent($amount, $percent));
    }

    public function testDecimalOfAndDivide(): void
    {
        self::assertSame('12.5', Decimal::of('12.5000'));
        self::assertSame('7', Decimal::of(7));
        self::assertSame('1.5', Decimal::of(1.5));
        self::assertSame('1200', Decimal::of('1.2E+3'));
        self::assertNull(Decimal::of('abc'));
        self::assertNull(Decimal::of(null));
        self::assertSame('25.25', Decimal::divide('2525', 2));
        self::assertSame('0.005', Decimal::divide('0.5', 2));
        self::assertSame('-1', Decimal::divide('-100', 2));
        self::assertSame('0', Decimal::divide('', 2));
    }

    public function testKeysDatesAndTasks(): void
    {
        self::assertSame('price', (new LostReasonKey('price'))->value);
        self::assertSame('2026-02-28', (new DateImmutable('2026-02-28'))->value);
        self::assertSame('2026-11-18', DateImmutable::of(new DateTimeImmutable('2026-11-18 23:30:00+00:00'))->value);
        self::assertSame('2026-02-28 00:00:00', (new DateImmutable('2026-02-28'))->toDateTime()->format('Y-m-d H:i:s'));
        foreach (['2026-02-30', '26-01-01', '0000-01-01', '2026-1-1'] as $bad) {
            try {
                new DateImmutable($bad);
                self::fail($bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['Price', '1x', str_repeat('a', 33)] as $bad) {
            try {
                new LostReasonKey($bad);
                self::fail($bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $ref = new CrmTaskRef('host', 'task 1');
        self::assertSame('host:task 1', $ref->key());
        foreach ([['Host', 'x'], ['host', '']] as [$type, $id]) {
            try {
                new CrmTaskRef($type, $id);
                self::fail($type);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $subject = SubjectRef::of(SubjectType::Deal, '01900000-0000-7000-8000-000000000001');
        $request = new CrmTaskRequest(new TenantRef('acme'), new ActorRef('ana'), $subject, 'T', new DateTimeImmutable(), null, str_repeat('a', 64), 'web', null, '/crm/deals/x');
        self::assertSame('web', $request->channel);
        foreach ([[str_repeat('a', 63), null], [str_repeat('a', 64), '//evil'], [str_repeat('a', 64), 'https://x']] as [$key, $link]) {
            try {
                new CrmTaskRequest(new TenantRef('acme'), new ActorRef('ana'), $subject, 'T', new DateTimeImmutable(), null, $key, 'web', null, $link);
                self::fail('accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('TOKEN_001', (new CrmTaskRefusedException('TOKEN_001'))->errorCode);
        self::assertNull((new CrmTaskRefusedException('nope', true))->errorCode);
        self::assertNull((new CrmTaskRefusedException(null))->errorCode);
    }

    public function testEnums(): void
    {
        self::assertSame([DealStatus::Open, DealStatus::Won, DealStatus::Lost], [DealStatus::of(StageKind::Open), DealStatus::of(StageKind::Won), DealStatus::of(StageKind::Lost)]);
        self::assertSame([false, true, true], [StageKind::Open->closes(), StageKind::Won->closes(), StageKind::Lost->closes()]);
        self::assertSame(['deals', false, 'deals'], [SubjectType::Deal->segment(), SubjectType::Deal->isRecord(), SubjectType::Deal->resource()->value]);
        self::assertNull(SubjectType::fromSegment('deals'));
        self::assertSame('party', FunnelKind::Party->value);
        self::assertSame('cancelled', NextActionStatus::Cancelled->value);
    }

    public function testEntities(): void
    {
        $now    = new DateTimeImmutable('2026-11-18 10:00:00');
        $funnel = new Funnel('f', 'acme', FunnelKind::Party, SubjectType::Contact, 'Life', 2, $now);
        self::assertSame(['f', 'acme', FunnelKind::Party, SubjectType::Contact, 'Life', 2, false, 1, $now, $now], [$funnel->getId(), $funnel->getTenantId(), $funnel->getKind(), $funnel->getSubjectType(), $funnel->getName(), $funnel->getPosition(), $funnel->isArchived(), $funnel->getVersion(), $funnel->getCreatedAt(), $funnel->getUpdatedAt()]);
        $stage = new Stage('s', 'acme', 'f', 'Lead', 0, StageKind::Open, 10, 5);
        self::assertSame(['s', 'acme', 'f', 'Lead', 0, StageKind::Open, 10, 5, false, 1], [$stage->getId(), $stage->getTenantId(), $stage->getFunnelId(), $stage->getName(), $stage->getPosition(), $stage->getKind(), $stage->getProbability(), $stage->getRotDays(), $stage->isArchived(), $stage->getVersion()]);
        $deal = new Deal('d', 'acme', 'f', 's', 'T', 'c1', 'p1', 'ana', $now, 'ana');
        self::assertSame(['acme', 'ana', 'ana', $now, null, null], [$deal->getTenantId(), $deal->getCreatedById(), $deal->getUpdatedById(), $deal->getCreatedAt(), $deal->getTrashedAt(), $deal->getAmount()]);
        $deal->unlinkCompany();
        $deal->unlinkContact();
        self::assertSame([null, null], [$deal->getCompanyId(), $deal->getContactId()]);
        $deal->trash($now, 'ana');
        $deal->restore($now, 'ana');
        self::assertSame(DealStatus::Open, $deal->getStatus());
        $change = new StageChange('x', 'acme', SubjectType::Deal, 'd', 'f', null, 's', 'ana', 'api', $now, null);
        self::assertSame(['x', 'acme', SubjectType::Deal, 'd', 'f', null, 's', 'ana', 'api', $now, null], [$change->getId(), $change->getTenantId(), $change->getItemType(), $change->getItemId(), $change->getFunnelId(), $change->getFromStageId(), $change->getToStageId(), $change->getActorId(), $change->getVia(), $change->getOccurredAt(), $change->getSecondsInFrom()]);
        $party = new PartyStage('p', 'acme', 'f', SubjectType::Contact, 'c', 's', $now);
        self::assertSame(['p', 'acme', 'f', SubjectType::Contact, 'c', 's', $now, 1], [$party->getId(), $party->getTenantId(), $party->getFunnelId(), $party->getSubjectType(), $party->getSubjectId(), $party->getStageId(), $party->getEnteredAt(), $party->getVersion()]);
        $action = new NextAction('n', 'acme', SubjectType::Deal, 'd', 'Call', $now, 'leo', 'crm', 'n', str_repeat('a', 64), 'ana', $now);
        self::assertSame(['n', 'acme', SubjectType::Deal, 'd', 'Call', $now, 'leo', NextActionStatus::Open, 'crm', 'n', str_repeat('a', 64), 'ana', $now, null, 1], [$action->getId(), $action->getTenantId(), $action->getSubjectType(), $action->getSubjectId(), $action->getTitle(), $action->getDueAt(), $action->getAssigneeId(), $action->getStatus(), $action->getTaskType(), $action->getTaskId(), $action->getDedupeKey(), $action->getCreatedById(), $action->getCreatedAt(), $action->getDoneAt(), $action->getVersion()]);
    }
}
