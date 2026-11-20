<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Api;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiGrant;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiReach;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiAffiliationSpec;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealFields;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiFields;
use Nowo\CrmKitBundle\Api\Input\CrmApiOwner;
use Nowo\CrmKitBundle\Api\Input\CrmApiPageRequest;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiSearchQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiTagRef;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Operation\CrmApiCursorCodec;
use Nowo\CrmKitBundle\Api\Operation\CrmApiErrors;
use Nowo\CrmKitBundle\Api\Operation\CrmApiIdempotency;
use Nowo\CrmKitBundle\Api\Operation\CrmApiOperations;
use Nowo\CrmKitBundle\Api\Read\CrmApiTime;
use Nowo\CrmKitBundle\Application\Pipeline\Decimal;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function count;
use function str_repeat;
use function strlen;
use function strtr;
use function substr;

/**
 * The API's model (0.3.0): grants and capabilities, input VOs, cursors, refusals, the catalogue.
 */
final class ApiModelTest extends TestCase
{
    private const ID = '0190a0a0-0000-7000-8000-000000000001';

    public function testTheCapabilityTable(): void
    {
        $expect = [
            // view, edit, manage × (no tick, amounts, consents, destructive)
            'read'          => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
            'read_amounts'  => [0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0],
            'read_consents' => [0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0],
            'create'        => [0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1],
            'edit'          => [0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1],
            'write_amounts' => [0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0],
            'trash'         => [0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1],
            'manage_reach'  => [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1],
        ];
        foreach (CrmApiCapability::cases() as $capability) {
            $row = [];
            foreach (CrmApiLevel::cases() as $level) {
                foreach ([[false, false, false], [true, false, false], [false, true, false], [false, false, true]] as [$a, $c, $d]) {
                    $row[] = $capability->allows(new CrmApiGrant($level, $a, $c, $d)) ? 1 : 0;
                }
            }
            self::assertSame($expect[$capability->value], $row, $capability->value);
        }
        self::assertSame(CrmPermission::Delete, CrmApiCapability::Trash->permission());
        self::assertSame(CrmPermission::AmountsView, CrmApiCapability::WriteAmounts->permission());
        self::assertSame(CrmPermission::Manage, CrmApiCapability::ManageReach->permission());
        self::assertNull(CrmApiCapability::forPermission(CrmPermission::Erase));
        self::assertSame(CrmApiCapability::Trash, CrmApiCapability::forPermission(CrmPermission::Delete));
        self::assertTrue(CrmApiLevel::Manage->includes(CrmApiLevel::View));
        self::assertFalse(CrmApiLevel::View->includes(CrmApiLevel::Edit));
    }

    public function testPrincipalReachAndOrigin(): void
    {
        $t = new TenantRef('acme');
        self::assertTrue(CrmApiReach::everything()->reaches($t));
        self::assertFalse(CrmApiReach::everything(['acme'])->reaches($t));
        self::assertTrue(CrmApiReach::limitedTo(['acme'])->reaches($t));
        self::assertFalse(CrmApiReach::limitedTo(['Acme'])->reaches($t));
        $p = new CrmApiPrincipal(new stdClass(), new ActorRef('ana'), new CrmApiGrant(CrmApiLevel::View), CrmApiReach::limitedTo([]), 't1', "My\u{200B}\ntoken", CrmApiPrincipal::CHANNEL_MCP);
        self::assertSame(['mcp', 't1', 'My token'], [$p->origin()->channel, $p->origin()->tokenId, $p->origin()->tokenName]);
        self::assertSame('token', AuditOrigin::clean("\x01"));
        self::assertSame(str_repeat('x', 120), AuditOrigin::clean(str_repeat('x', 200)));
        self::assertSame('token', AuditOrigin::clean("\xff"));
        foreach ([['', 'api', 'ana'], ['t', 'API', 'ana'], ['t', 'api', ActorRef::ANONYMISED]] as [$token, $channel, $actor]) {
            try {
                new CrmApiPrincipal(new stdClass(), new ActorRef($actor), new CrmApiGrant(CrmApiLevel::View), CrmApiReach::everything(), $token, 'n', $channel);
                self::fail('accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new AuditOrigin('api', ' bad', 'x');
    }

    public function testInputVos(): void
    {
        self::assertSame(64, strlen((new IdempotencyKey('abc'))->hash()));
        foreach (['', str_repeat('a', 256), 'a b', 'é'] as $bad) {
            self::assertSame(CrmApiCode::IDEMP_INVALID, $this->code(static fn (): IdempotencyKey => new IdempotencyKey($bad)));
        }
        self::assertSame(CrmApiCode::IDEMP_MISSING, $this->code(static fn (): IdempotencyKey => IdempotencyKey::from(null)));
        self::assertSame('k', IdempotencyKey::from('k')->value);
        foreach (['"3"', 'W/"3"', '3'] as $header) {
            self::assertSame(3, RecordVersion::fromIfMatch($header)?->value);
        }
        self::assertNull(RecordVersion::fromIfMatch(null));
        foreach (['*', '0', '2147483648', 'abc', '"3', 'W/3"', 'W/3'] as $bad) {
            self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?\Nowo\CrmKitBundle\Api\Input\RecordVersion => RecordVersion::fromIfMatch($bad)), $bad);
        }
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): RecordVersion => new RecordVersion(0)));
        self::assertSame(CrmApiCode::VERSION_MISSING, $this->code(static fn (): RecordVersion => RecordVersion::required(null)));
        self::assertSame(CrmApiCode::INPUT_CURSOR, $this->code(static fn (): CrmApiCursor => new CrmApiCursor('a+b')));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiPageRequest => new CrmApiPageRequest(null, 101)));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiSearchQuery => new CrmApiSearchQuery([])));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiSearchQuery => new CrmApiSearchQuery(text: str_repeat('x', 101))));
        self::assertSame(['types' => ['company', 'contact'], 'q' => null, 'role' => null, 'tag' => null, 'owner' => null, 'stage' => null, 'sort' => 'name'], (new CrmApiSearchQuery())->filters());

        self::assertSame('me', CrmApiOwner::parse('me')->canonical());
        self::assertSame('none', CrmApiOwner::parse('none')->canonical());
        self::assertSame('leo', CrmApiOwner::parse('leo')->resolve(new ActorRef('ana')));
        self::assertSame('ana', CrmApiOwner::me()->resolve(new ActorRef('ana')));
        self::assertNull(CrmApiOwner::none()->resolve(new ActorRef('ana')));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiOwner => CrmApiOwner::parse(5)));

        $draft = CrmApiRecordDraft::fromArray(SubjectType::Contact, ['last_name' => 'L', 'custom' => ['score' => 3, 'vip' => true, 'note' => null], 'roles' => ['customer'], 'tag_ids' => [self::ID], 'owner' => 'none', 'company' => ['company_id' => self::ID, 'purposes' => ['billing']]]);
        self::assertSame(['contact', 'none', ['billing']], [$draft->canonical()['type'], $draft->owner->canonical(), $draft->company?->purposes]);
        foreach ([['custom' => ['Bad' => 1]], ['custom' => [1, 2]], ['roles' => ['Bad']], ['roles' => ['a', 'a']], ['tag_ids' => 'x'], ['last_name' => 5], ['last_name' => "\xff"], ['company' => 'x'], ['email' => str_repeat('a', 600)]] as $bad) {
            self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiRecordDraft => CrmApiRecordDraft::fromArray(SubjectType::Contact, $bad)), (string) json_encode($bad));
        }
        self::assertSame(CrmApiCode::INPUT_MISSING, $this->code(static fn (): CrmApiRecordDraft => CrmApiRecordDraft::fromArray(SubjectType::Contact, ['company' => ['role' => 'x']])));
        $changes = CrmApiRecordChanges::fromArray(SubjectType::Company, ['city' => null, 'tag_ids' => [], 'roles' => [], 'owner' => 'me', 'custom' => []]);
        foreach (['city', 'tag_ids', 'roles', 'owner', 'custom'] as $field) {
            self::assertTrue($changes->has($field), $field);
        }
        self::assertFalse($changes->has('name'));
        self::assertFalse((new CrmApiRecordChanges(SubjectType::Company))->has('custom'));
        self::assertSame(self::ID, CrmApiAffiliationSpec::fromArray(['contact_id' => self::ID, 'company_id' => self::ID, 'primary' => true])->canonical()['contact_id']);
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiAffiliationSpec => CrmApiAffiliationSpec::fromArray(['contact_id' => self::ID, 'company_id' => self::ID, 'primary' => 'yes'])));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiTagRef => CrmApiTagRef::byName(' ')));
        self::assertSame(['tag_id' => self::ID, 'tag_name' => null], CrmApiTagRef::byId(new RecordId(self::ID))->canonical());

        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?int => CrmApiFields::int(['n' => 'x'], 'n', 0, 1)));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?\Nowo\CrmKitBundle\Domain\Value\RecordId => CrmApiFields::id(['n' => 'x'], 'n')));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?\Nowo\CrmKitBundle\Domain\Value\ActorRef => CrmApiFields::actor(['n' => ActorRef::ANONYMISED], 'n')));
        self::assertNull(CrmApiFields::actor([], 'n'));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?array => CrmApiFields::keys(['n' => [1]], 'n', '/x/')));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?array => CrmApiFields::object(['n' => [1]], 'n')));
        self::assertSame([], CrmApiFields::object(['n' => []], 'n'));
        self::assertSame(CrmApiCode::INPUT_MISSING, $this->code(static fn (): string => CrmApiFields::requiredText([], 'n', 3)));

        $deal = CrmApiDealDraft::fromArray(['title' => 'T', 'funnel_id' => self::ID, 'stage_id' => self::ID, 'amount' => '10', 'currency' => 'EUR', 'expected_close' => '2026-02-28']);
        self::assertSame(['10', 'EUR', '2026-02-28', 'me'], [$deal->canonical()['amount'], $deal->canonical()['currency'], $deal->canonical()['expected_close'], $deal->canonical()['owner']]);
        self::assertSame('currency', $this->refusal(static fn (): ?\Nowo\CrmKitBundle\Domain\Value\Money => CrmApiDealFields::money(['amount' => '10', 'currency' => 'eur']))->extra['field'] ?? null);
        self::assertSame('amount', $this->refusal(static fn (): ?\Nowo\CrmKitBundle\Domain\Value\Money => CrmApiDealFields::money(['amount' => 10, 'currency' => 'EUR']))->extra['field'] ?? null);
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?\Nowo\CrmKitBundle\Domain\Value\DateImmutable => CrmApiDealFields::date(['d' => '2026-02-30'], 'd')));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?\Nowo\CrmKitBundle\Domain\Value\DateImmutable => CrmApiDealFields::date(['d' => 5], 'd')));
        self::assertNull(CrmApiDealFields::date([], 'd'));
        self::assertNull(CrmApiDealFields::instant([], 'd'));
        self::assertSame('2026-11-25T08:00:00.000000+00:00', CrmApiTime::format(CrmApiDealFields::instant(['d' => '2026-11-25T09:00:00+01:00'], 'd')));
        self::assertSame('2026-11-25T09:00:00.250000+00:00', CrmApiTime::format(CrmApiDealFields::instant(['d' => '2026-11-25T09:00:00.25Z'], 'd')));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): ?DateTimeImmutable => CrmApiDealFields::instant(['d' => '2026-13-45T99:00:00Z'], 'd')));
        $changes = CrmApiDealChanges::fromArray(['currency' => null, 'amount' => null, 'title' => 'x']);
        self::assertTrue($changes->touchesAmounts());
        self::assertFalse(CrmApiDealChanges::fromArray(['title' => 'x'])->touchesAmounts());
        self::assertSame(['title', 'amount'], $changes->canonical()['fields']);
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiDealQuery => new CrmApiDealQuery(status: DealStatus::Trashed)));
        self::assertSame(CrmApiCode::INPUT_MALFORMED, $this->code(static fn (): CrmApiDealQuery => new CrmApiDealQuery(text: str_repeat('x', 101))));
        self::assertSame('updated', (new CrmApiDealQuery(stageIds: [new RecordId(self::ID)]))->filters()['sort']);
        self::assertSame('1.0001', Decimal::add('0.5', '0.5001'));
        self::assertSame('-0.5', Decimal::add('0.5', '-1'));
        self::assertStringStartsWith('1844674407370955.', Decimal::add('922337203685477.5807', '922337203685477.5807'), 'past the integer range: a float, never a wrap');
        self::assertStringStartsWith('-1844674407370955.', Decimal::add('-922337203685477.5807', '-922337203685477.5807'));
    }

    public function testSealedCursors(): void
    {
        $codec  = new CrmApiCursorCodec('secret');
        $filter = CrmApiCursorCodec::filterHash(['q' => 'x']);
        $sealed = $codec->seal(CrmApiOperation::Search, $filter, ['v' => 'Ada', 'id' => self::ID]);
        self::assertStringNotContainsString('Ada', $sealed);
        self::assertSame(['v' => 'Ada', 'id' => self::ID], $codec->open(new CrmApiCursor($sealed), CrmApiOperation::Search, $filter));
        $tampered = substr($sealed, 0, -2) . ($sealed[-2] === 'A' ? 'B' : 'A') . $sealed[-1];
        foreach ([
            static fn (): array => $codec->open(new CrmApiCursor($tampered), CrmApiOperation::Search, $filter),
            static fn (): array => $codec->open(new CrmApiCursor($sealed), CrmApiOperation::NotesList, $filter),
            static fn (): array => $codec->open(new CrmApiCursor($sealed), CrmApiOperation::Search, 'other'),
            static fn (): array => (new CrmApiCursorCodec('rotated'))->open(new CrmApiCursor($sealed), CrmApiOperation::Search, $filter),
            static fn (): array => $codec->open(new CrmApiCursor('abc'), CrmApiOperation::Search, $filter),
            static fn (): array => $codec->open(new CrmApiCursor(strtr(base64_encode(str_repeat('x', 40)), '+/=', '-_A')), CrmApiOperation::Search, $filter),
        ] as $i => $open) {
            self::assertSame(CrmApiCode::INPUT_CURSOR, $this->code($open), (string) $i);
        }
    }

    public function testRefusalsAndErrorMapping(): void
    {
        self::assertSame([404, 'Not found.'], [CrmApiRefusal::notFound()->status, CrmApiRefusal::notFound()->getMessage()]);
        self::assertSame(403, CrmApiRefusal::token()->status);
        self::assertSame(CrmApiCode::TOKEN, CrmApiRefusal::foreign('TOKEN_001')->errorCode);
        self::assertSame(CrmApiCode::TASK_REFUSED, CrmApiRefusal::foreign('CONFLICT_001')->errorCode);
        self::assertSame(CrmApiCode::TASK_REFUSED, CrmApiRefusal::foreign('NOPE_999')->errorCode);
        self::assertSame(CrmApiCode::TASK_REFUSED, CrmApiRefusal::foreign(null)->errorCode);
        foreach ([static fn (): CrmApiRefusal => CrmApiRefusal::of('NOPE'), static fn (): CrmApiRefusal => CrmApiRefusal::of(CrmApiCode::TOKEN, ['x' => 1])] as $bad) {
            try {
                $bad();
                self::fail('accepted');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $map = [
            CrmApiCode::NOT_FOUND        => new NotAvailableException(),
            CrmApiCode::DENIED           => new AccessDeniedException(),
            CrmApiCode::INPUT_REFUSED    => InvalidInputException::field('name', 'error.required'),
            CrmApiCode::LOST_REASON      => InvalidInputException::field('lost_reason', 'error.lost_reason_required'),
            CrmApiCode::STALE            => new ConflictException(ConflictException::STALE),
            CrmApiCode::MERGED           => new ConflictException(ConflictException::MERGED, 'x'),
            CrmApiCode::TRASHED          => new ConflictException(ConflictException::TRASHED),
            CrmApiCode::LIMIT            => new ConflictException(ConflictException::LIMIT),
            CrmApiCode::STAGE            => new ConflictException(ConflictException::STAGE_INVALID),
            CrmApiCode::WRITE_CONFLICT   => new ConflictException(ConflictException::MERGE_STALE),
            CrmApiCode::TASK_UNAVAILABLE => new CrmTaskRefusedException('ABC_123', true),
            CrmApiCode::TOKEN            => new CrmTaskRefusedException('TOKEN_001'),
        ];
        foreach ($map as $code => $exception) {
            self::assertSame($code, $this->code(static fn (): mixed => CrmApiErrors::run(static fn (): never => throw $exception, static fn (): array => ['current_version' => 3, 'merged_into_id' => null])), $code);
        }
        self::assertSame(['current_version' => 3], $this->refusal(static fn (): mixed => CrmApiErrors::run(static fn (): never => throw new ConflictException(ConflictException::STALE), static fn (): array => ['current_version' => 3]))->extra);
        $this->expectException(RuntimeException::class);
        CrmApiErrors::run(static fn (): never => throw new RuntimeException('x'));
    }

    public function testCatalogueAndAvailability(): void
    {
        self::assertFalse((new CrmApiOperations(false))->available(CrmApiOperation::DealCreate));
        self::assertTrue((new CrmApiOperations(true))->available(CrmApiOperation::DealCreate));
        self::assertCount(count(CrmApiOperation::cases()) - 8, (new CrmApiOperations())->all());
        self::assertSame('crm_deal_trash', CrmApiOperation::DealTrash->toolName());
        self::assertSame(CrmApiOperation::VERSION_REQUIRED, CrmApiOperation::DealUpdate->needsBaseVersion());
        self::assertSame(['amounts'], CrmApiOperation::FunnelStats->ticks());
        self::assertSame(['consents'], CrmApiOperation::ConsentsGet->ticks());
        self::assertSame(CrmApiOperation::KIND_READ, CrmApiOperation::Identity->kind());
        self::assertContains(CrmApiCode::LOST_REASON, CrmApiOperation::DealMoveStage->codes());
        self::assertContains(CrmApiCode::TASK_UNAVAILABLE, CrmApiOperation::NextActionSet->codes());
        self::assertSame(CrmApiCode::NOT_FOUND, $this->code(static fn () => (new CrmApiOperations())->require(CrmApiOperation::DealGet)));
        self::assertSame(64, strlen(CrmApiIdempotency::scope('api', 't')));
        self::assertSame(CrmApiIdempotency::fingerprint(CrmApiOperation::Search, ['b' => 1, 'a' => ['y' => 1, 'x' => [2, 1]]]), CrmApiIdempotency::fingerprint(CrmApiOperation::Search, ['a' => ['x' => [2, 1], 'y' => 1], 'b' => 1]));
        self::assertNotSame(CrmApiIdempotency::fingerprint(CrmApiOperation::Search, ['a' => [1, 2]]), CrmApiIdempotency::fingerprint(CrmApiOperation::Search, ['a' => [2, 1]]));
    }

    private function refusal(callable $call): CrmApiRefusal
    {
        try {
            $call();
        } catch (CrmApiRefusal $refusal) {
            return $refusal;
        }
        self::fail('not refused');
    }

    private function code(callable $call): string
    {
        return $this->refusal($call)->errorCode;
    }
}
