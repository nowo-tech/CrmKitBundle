<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotException;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunState;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotText;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotActivity;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HttpHubSpotReader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

use function array_map;
use function implode;
use function range;
use function str_repeat;

/**
 * The HubSpot engagements import's pure parts (0.12.0): the text rules (HTML ⇒ plain text, titles, times, minutes),
 * the kinds, the scope and the run cursor with engagement stages, the reader's engagement pages and the kinds a
 * configuration imports.
 */
final class HubSpotActivitiesUnitTest extends TestCase
{
    public function testHtmlBecomesPlainText(): void
    {
        self::assertSame("Hi & bye\n\nNext\n\nLast", HubSpotText::plain("<p>Hi &amp; <b>bye</b></p><style>p{}</style><SCRIPT type='x'>alert(1)</script >\r\nNext<br/>\n\n\n\n<div>Last\u{200E}\u{0007}</div>", 100));
        self::assertNull(HubSpotText::plain(null, 10));
        self::assertNull(HubSpotText::plain("\xff", 10), 'not UTF-8');
        self::assertNull(HubSpotText::plain('<p>&nbsp;</p>', 10));
        self::assertSame('abcd…', HubSpotText::plain('abcdefgh', 5));
        self::assertSame('a b', HubSpotText::line("a\n\t b", 10));
        self::assertNull(HubSpotText::line('', 10));
        self::assertSame(str_repeat('x', 9) . '…', HubSpotText::line(str_repeat('x', 30), 10));
    }

    public function testTimesAndMinutes(): void
    {
        self::assertSame('2026-01-01 10:00:00', HubSpotText::time('1767261600000')?->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-01 09:00:00.123456', HubSpotText::time('2026-01-01T10:00:00.1234567+01:00')?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-01 10:00:00', HubSpotText::time(' 2026-01-01T10:00Z ')?->format('Y-m-d H:i:s'));
        foreach ([null, '', 'yesterday', '2026-01-01', '1000', '4133980800000', '2026-13-45T10:00:00Z', '1234567890123456'] as $bad) {
            self::assertNull(HubSpotText::time($bad), (string) $bad);
        }
        self::assertSame([2, 0, 1440, null, null, null], [HubSpotText::minutes('90000', 1440), HubSpotText::minutes('0', 1440), HubSpotText::minutes('999999999', 1440), HubSpotText::minutes(null, 1440), HubSpotText::minutes('1.5', 1440), HubSpotText::minutes('1234567890123456', 1440)]);
    }

    public function testKindsScopeAndRunState(): void
    {
        self::assertSame(['note', 'call', 'meeting', 'task'], array_map(static fn (HubSpotActivity $k): string => $k->refType(), HubSpotActivity::cases()));
        self::assertSame(['note', 'engagement', 'engagement', 'next_action'], array_map(static fn (HubSpotActivity $k): string => $k->targetType(), HubSpotActivity::cases()));
        foreach (HubSpotActivity::cases() as $kind) {
            self::assertSame(['crm.objects.contacts.read'], $kind->scopes());
            self::assertContains('hs_timestamp', $kind->properties());
            self::assertContains('hubspot_owner_id', $kind->properties());
        }
        $scope = new HubSpotImportScope([], [], [HubSpotActivity::Task, HubSpotActivity::Note, HubSpotActivity::Task]);
        self::assertSame([[], [HubSpotActivity::Note, HubSpotActivity::Task], SubjectType::Contact], [$scope->objects, $scope->activities, $scope->subjectType()]);
        self::assertSame(['activity:notes', 'activity:tasks'], $scope->columns());
        self::assertEquals($scope, HubSpotImportScope::fromColumns($scope->columns()));
        $mixed = new HubSpotImportScope([HubSpotObject::Company], [new HubSpotObjectId('7')], [HubSpotActivity::Call]);
        self::assertSame(['companies', 'list:7', 'activity:calls'], $mixed->columns());
        self::assertSame(SubjectType::Company, $mixed->subjectType());
        try {
            HubSpotImportScope::fromColumns(['activity:emails']);
            self::fail('unknown kind');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $state = HubSpotRunState::start(new HubSpotAccountId('42'), $mixed, ['7' => HubSpotObject::Company]);
        self::assertSame(['owners', 'companies', 'calls', 'lists'], $state->stages);
        $state = $state->advance(null)->advance(null, 2);
        self::assertSame([HubSpotActivity::Call, null], [$state->activity(), $state->object()]);
        $state = $state->advance(new HubSpotCursor('5'), 5, 1)->advance(null, 3);
        self::assertSame(['lists', ['companies' => 2, 'calls' => 8], 1, null], [$state->stage, $state->seen, $state->skipped, $state->activity()]);
    }

    public function testTheReaderPagesEngagementsWithTheirAssociations(): void
    {
        $fake                       = new FakeHubSpot();
        $fake->engagements['notes'] = [
            '31' => ['properties' => ['hs_note_body' => '<p>x</p>', 'hs_timestamp' => '2026-01-01T00:00:00Z', 'other' => 'never asked'], 'contacts' => ['2001', '2002'], 'companies' => ['1001']],
            '32' => ['properties' => []],
        ];
        $reader = new HttpHubSpotReader(new MockHttpClient($fake, HttpHubSpotReader::BASE_URL));
        $token  = new HubSpotToken($fake->token);
        self::assertSame(2, $reader->count($token, HubSpotActivity::Note));
        $page = $reader->engagements($token, HubSpotActivity::Note, null, 1);
        $note = $page->engagements[0];
        self::assertSame(['31', ['hs_timestamp' => '2026-01-01T00:00:00Z', 'hubspot_owner_id' => null, 'hs_note_body' => '<p>x</p>'], ['2001', '2002'], ['1001'], '1'], [$note->id->value, $note->properties, $this->ids($note->contacts), $this->ids($note->companies), $page->next?->value], 'duplicates (one per association type) collapse');
        self::assertSame('<p>x</p>', $note->value('hs_note_body'));
        self::assertNull($note->value('nope'));
        $last = $reader->engagements($token, HubSpotActivity::Note, new HubSpotCursor('1'), 1);
        self::assertSame([[], [], null], [$last->engagements[0]->contacts, $last->engagements[0]->companies, $last->next]);
        self::assertStringContainsString('associations=contacts%2Ccompanies', $fake->requests[2][1]);
        // At most 100 ids of each kind are kept.
        $many = implode(',', array_map(static fn (int $i): string => '{"id": "' . $i . '", "type": "t"}', range(1, 120)));
        $fake->failNext('/crm/v3/objects/notes', 200, [], '{"results": [{"id": "1", "properties": {}, "associations": {"contacts": {"results": [' . $many . ']}}}]}');
        self::assertCount(HttpHubSpotReader::MAX_ASSOCIATED, $reader->engagements($token, HubSpotActivity::Note, null, 10)->engagements[0]->contacts);
        foreach (['{"results": [{"id": "1", "associations": {"contacts": "x"}}]}', '{"results": [{"id": "1", "associations": {"companies": {"results": [{"id": "0"}]}}}]}', '{"results": [{"id": "1", "associations": {"companies": {"results": [5]}}}]}'] as $body) {
            $fake->failNext('/crm/v3/objects/notes', 200, [], $body);
            try {
                $reader->engagements($token, HubSpotActivity::Note, null, 10);
                self::fail('bad shape');
            } catch (HubSpotException $e) {
                self::assertSame(HubSpotException::BAD_RESPONSE, $e->errorCode);
            }
        }
    }

    public function testTheKindsAConfigurationImports(): void
    {
        self::assertSame([], ServiceDefinitions::hubspotActivities(false, true));
        self::assertSame(['notes', 'calls', 'meetings'], ServiceDefinitions::hubspotActivities(true, false));
        self::assertSame(['notes', 'calls', 'meetings', 'tasks'], ServiceDefinitions::hubspotActivities(true, true));
    }

    /**
     * @param list<HubSpotObjectId> $ids
     *
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_map(static fn (HubSpotObjectId $i): string => $i->value, $ids);
    }
}
