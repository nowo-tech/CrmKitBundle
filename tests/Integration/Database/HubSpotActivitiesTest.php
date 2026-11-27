<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\HubSpot\HubSpotEngagementWriter;
use Nowo\CrmKitBundle\Application\HubSpot\NeedsPersonException;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotActivity;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\ImportRowView;
use Nowo\CrmKitBundle\Domain\Read\ImportView;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * HubSpot engagements (0.12.0, Beacon spec 254 FR-100 A): notes ⇒ notes, calls and meetings ⇒ logged activities,
 * tasks ⇒ next actions, on the records an import of the same account made; HTML bodies as plain text, owners as
 * authors and assignees, associations as subject rows and attendees; a re-run updates and never duplicates; the
 * switches (`activities.enabled`, `pipeline.enabled`) refuse at start and skip at run; access per record, attended and
 * unattended.
 */
final class HubSpotActivitiesTest extends HubSpotTestCase
{
    /** @var array<string, mixed> */
    private const ON = ['activities' => ['enabled' => true], 'pipeline' => ['enabled' => true]];

    public function testEngagementsLandOnTheImportedRecordsAndAReRunUpdatesWithoutDuplicates(): void
    {
        $fake                        = $this->hubspot(self::ON);
        $this->ctx()->emails['acme'] = ['leo' => 'leo@owners.example'];
        $fake->owners                = ['501' => 'leo@owners.example'];
        $this->seed($fake);
        $this->importRecords($fake);
        $fake->engagements = [
            'notes' => [
                '3001' => ['properties' => ['hs_timestamp' => '2026-03-01T10:00:00.123Z', 'hubspot_owner_id' => '501', 'hs_note_body' => '<p>Hello <b>world</b> &amp; co</p><script>alert(1)</script><div>Line&nbsp;two</div>'], 'contacts' => ['2001'], 'companies' => ['1001']],
                '3002' => ['properties' => ['hs_note_body' => 'Nobody']],
                '3003' => ['properties' => ['hs_note_body' => 'Unknown'], 'contacts' => ['9999']],
                '3004' => ['properties' => ['hs_note_body' => '<p> </p>'], 'contacts' => ['2001']],
            ],
            'calls' => [
                '4001' => ['properties' => ['hs_timestamp' => '1767261600000', 'hs_call_title' => "Intro\ncall", 'hs_call_body' => '<p>Asked</p>', 'hs_call_direction' => 'INBOUND', 'hs_call_duration' => '90000', 'hubspot_owner_id' => '777'], 'contacts' => ['2001', '2002'], 'companies' => ['1001']],
                '4002' => ['properties' => ['hs_call_title' => 'No time'], 'contacts' => ['2001']],
            ],
            'meetings' => [
                '5001' => ['properties' => ['hs_meeting_start_time' => '2026-04-01T09:00:00Z', 'hs_meeting_end_time' => '2026-04-01T10:00:00Z', 'hs_meeting_title' => 'Kick-off', 'hs_meeting_location' => 'Room 1', 'hs_meeting_outcome' => 'CANCELED'], 'contacts' => ['2001', '2002'], 'companies' => ['1001']],
                '5002' => ['properties' => ['hs_timestamp' => '2026-04-02T09:00:00Z', 'hs_meeting_end_time' => '2026-04-01T09:00:00Z', 'hs_meeting_title' => 'Backwards', 'hs_meeting_location' => 'http://meet.example/room'], 'companies' => ['1001']],
            ],
            'tasks' => [
                '6001' => ['properties' => ['hs_timestamp' => '2026-05-01T09:00:00Z', 'hs_task_subject' => 'Send the quote', 'hs_task_status' => 'COMPLETED', 'hubspot_owner_id' => '501'], 'contacts' => ['2001'], 'companies' => ['1001']],
                '6002' => ['properties' => ['hs_timestamp' => '2026-05-02T09:00:00Z', 'hs_task_subject' => 'Call back', 'hs_task_status' => 'NOT_STARTED'], 'companies' => ['1001']],
                '6003' => ['properties' => ['hs_timestamp' => '2026-05-02T09:00:00Z'], 'companies' => ['1001']],
                '6004' => ['properties' => ['hs_task_subject' => 'No due'], 'companies' => ['1001']],
            ],
        ];
        $id   = $this->activities();
        $done = $this->finish($id);
        self::assertSame(['created' => 6, 'updated' => 0, 'duplicate' => 0, 'invalid' => 6, 'limit' => 0], $done->counts);
        $messages = [];
        foreach ($done->rows as $row) {
            $messages[$row->data['object'] . ':' . $row->data['id']] = $row->message;
        }
        self::assertSame('import.hubspot.no_record', $messages['note:3002']);
        self::assertSame('import.hubspot.no_record', $messages['note:3003']);
        self::assertSame('error.required', $messages['note:3004']);
        self::assertSame('activity_error.time', $messages['call:4002']);
        self::assertSame('error.required', $messages['task:6003']);
        self::assertSame('activity_error.time', $messages['task:6004']);
        $ada  = $this->target('2001');
        $acme = $this->target('1001');
        // Notes: plain text on the first contact, HubSpot's time, the owner as author.
        self::assertSame("Hello world & co\nLine two", $this->text('SELECT body FROM nowo_crm_note WHERE subject_id = ?', [$ada]));
        self::assertSame('leo', $this->text('SELECT author_id FROM nowo_crm_note WHERE subject_id = ?', [$ada]));
        self::assertStringStartsWith('2026-03-01 10:00:00', $this->text('SELECT created_at FROM nowo_crm_note WHERE subject_id = ?', [$ada]));
        // Calls: subject rows for every imported record, a one-line title, direction, minutes rounded up.
        $call = $this->target('4001', 'call');
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$call]));
        self::assertSame($ada, $this->text('SELECT subject_id FROM nowo_crm_engagement_subject WHERE engagement_id = ? AND is_primary = ?', [$call, true]));
        self::assertSame(['Intro call', 'Asked', 'in', '2', ''], [$this->text('SELECT title FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text('SELECT body FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text('SELECT direction FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text('SELECT duration_minutes FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text("SELECT COALESCE(author_id, '') FROM nowo_crm_engagement WHERE id = ?", [$call])]);
        // Meetings: the slot in UTC, the contacts as attendees, cancelled; a backwards end gets the default length.
        $meeting = $this->target('5001', 'meeting');
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$meeting]));
        self::assertSame(['Room 1', 'UTC'], [$this->text('SELECT place FROM nowo_crm_engagement WHERE id = ?', [$meeting]), $this->text('SELECT timezone FROM nowo_crm_engagement WHERE id = ?', [$meeting])]);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE id = ? AND cancelled = ?', [$meeting, true]));
        $backwards = $this->target('5002', 'meeting');
        self::assertStringStartsWith('2026-04-02 09:30:00', $this->text('SELECT ends_at FROM nowo_crm_engagement WHERE id = ?', [$backwards]));
        self::assertSame($acme, $this->text('SELECT subject_id FROM nowo_crm_engagement WHERE id = ?', [$backwards]), 'no contact: the company');
        self::assertSame('', $this->text("SELECT COALESCE(place, '') FROM nowo_crm_engagement WHERE id = ?", [$backwards]), 'a place that is not text nor an https address is left out');
        // Tasks: next actions on the built-in list, done when completed, assignee by e-mail.
        $sent = $this->target('6001', 'task');
        self::assertSame(['done', 'leo', 'crm', $sent, HubSpotEngagementWriter::dedupeKey($fake->account, '6001')], [$this->text('SELECT status FROM nowo_crm_next_action WHERE id = ?', [$sent]), $this->text('SELECT assignee_id FROM nowo_crm_next_action WHERE id = ?', [$sent]), $this->text('SELECT task_type FROM nowo_crm_next_action WHERE id = ?', [$sent]), $this->text('SELECT task_id FROM nowo_crm_next_action WHERE id = ?', [$sent]), $this->text('SELECT dedupe_key FROM nowo_crm_next_action WHERE id = ?', [$sent])]);
        self::assertSame('open', $this->text('SELECT status FROM nowo_crm_next_action WHERE id = ?', [$this->target('6002', 'task')]));
        // Skipped associations of the new items: note 3001's company, task 6001's company (one record each).
        self::assertSame(2, $this->cursor($id)['skipped']);
        // Rows: kind and HubSpot id only; no body anywhere outside the activity tables.
        foreach ($this->connection()->fetchFirstColumn('SELECT data FROM nowo_crm_import_row') as $data) {
            self::assertFalse(str_contains(is_string($data) ? $data : '', 'Hello'));
        }
        // Rows name the primary record a person may see.
        self::assertSame($ada, $done->rows[0]->recordId);

        // A re-run updates what it made, adds missing associations, never clears with an empty value.
        $fake->engagements['notes']['3001']['properties']['hs_note_body']           = 'Changed';
        $fake->engagements['calls']['4001']['properties']                           = ['hs_timestamp' => '1767261600000', 'hs_call_title' => '', 'hs_call_direction' => 'OUTBOUND', 'hs_call_duration' => 'x'];
        $fake->engagements['meetings']['5001']['contacts']                          = ['2001', '2002', '2003'];
        $fake->engagements['meetings']['5001']['properties']['hs_meeting_location'] = '';
        $fake->engagements['tasks']['6002']['properties']                           = ['hs_task_subject' => 'Call back soon', 'hs_task_status' => 'COMPLETED', 'hubspot_owner_id' => '501'];
        // A next action whose reference was lost is found by its dedupe key; a deleted note is made again.
        $this->connection()->executeStatement("DELETE FROM nowo_crm_external_ref WHERE external_id = '6002'");
        $this->connection()->executeStatement('DELETE FROM nowo_crm_note');
        $second = $this->finish($this->activities());
        self::assertSame(['created' => 1, 'updated' => 5, 'duplicate' => 0, 'invalid' => 6, 'limit' => 0], $second->counts);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));
        self::assertSame('Changed', $this->text('SELECT body FROM nowo_crm_note'));
        self::assertSame(['Intro call', 'Asked', 'out', '2'], [$this->text('SELECT title FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text('SELECT body FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text('SELECT direction FROM nowo_crm_engagement WHERE id = ?', [$call]), $this->text('SELECT duration_minutes FROM nowo_crm_engagement WHERE id = ?', [$call])]);
        self::assertSame(4, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$meeting]), 'the new contact is added');
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$meeting]));
        self::assertSame('Room 1', $this->text('SELECT place FROM nowo_crm_engagement WHERE id = ?', [$meeting]));
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'));
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        $back = $this->target('6002', 'task');
        self::assertSame(['Call back soon', 'done', 'leo'], [$this->text('SELECT title FROM nowo_crm_next_action WHERE id = ?', [$back]), $this->text('SELECT status FROM nowo_crm_next_action WHERE id = ?', [$back]), $this->text('SELECT assignee_id FROM nowo_crm_next_action WHERE id = ?', [$back])]);
        self::assertContains('crm.import.completed', $this->recorder()->actions());
    }

    public function testRecordsChangedSinceTheFirstRunAreLeftAlone(): void
    {
        $fake = $this->hubspot(self::ON);
        $this->seed($fake);
        $this->importRecords($fake);
        $fake->engagements['notes'] = [
            '3001' => ['properties' => ['hs_note_body' => 'On Ada'], 'contacts' => ['2001']],
            '3002' => ['properties' => ['hs_note_body' => 'On Bob'], 'contacts' => ['2002']],
            '3003' => ['properties' => ['hs_note_body' => 'On Cy'], 'contacts' => ['2003']],
        ];
        $this->finish($this->activities([HubSpotActivity::Note]));
        $ada = $this->target('2001');
        $bob = $this->target('2002');
        $cy  = $this->target('2003');
        $this->records()->trash(SubjectType::Contact, $ada, 1);
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $cy, $bob, [], 1, 1);
        $this->ctx()->denied[] = 'edit:' . $cy;
        $view                  = $this->finish($this->activities([HubSpotActivity::Note]));
        self::assertSame(['created' => 0, 'updated' => 0, 'duplicate' => 1, 'invalid' => 2, 'limit' => 0], $view->counts);
        self::assertSame(['error.record_trashed', 'error.forbidden', 'error.forbidden'], array_map(static fn (ImportRowView $r): ?string => $r->message, $view->rows));
        // Erased: the contact's reference is a tombstone, so a new note finds no record.
        $this->ctx()->denied = [];
        $this->service(CrmPersonalDataInterface::class)->erase($cy);
        $fake->engagements['notes'] = ['3009' => ['properties' => ['hs_note_body' => 'After'], 'contacts' => ['2003']]];
        self::assertSame('error.record_erased', $this->finish($this->activities([HubSpotActivity::Note]))->rows[0]->message);
    }

    public function testAPersonWithoutEditOnTheContactPutsItOnTheCompany(): void
    {
        $fake = $this->hubspot(self::ON);
        $this->seed($fake);
        $this->importRecords($fake);
        $fake->engagements['calls'] = ['4001' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00+01:00'], 'contacts' => ['2001'], 'companies' => ['1001']], '4002' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z'], 'contacts' => ['2001']]];
        $this->ctx()->denied[]      = 'edit:' . $this->target('2001');
        $view                       = $this->finish($this->activities([HubSpotActivity::Call]));
        self::assertSame([null, 'error.forbidden'], [$view->rows[0]->message, $view->rows[1]->message], 'no record the person may edit');
        $call = $this->target('4001', 'call');
        self::assertSame($this->target('1001'), $this->text('SELECT subject_id FROM nowo_crm_engagement_subject WHERE engagement_id = ? AND is_primary = ?', [$call, true]));
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$call]), 'the contact is still a subject row (the feed checks each viewer)');
        self::assertStringStartsWith('2025-12-31 23:00:00', $this->text('SELECT occurred_at FROM nowo_crm_engagement WHERE id = ?', [$call]));
    }

    public function testOtherAssociatedRecordsNeedTheSameAccessAsTheRecordsImport(): void
    {
        $fake = $this->hubspot(self::ON + ['import' => ['hubspot' => ['page_size' => 50]]]);
        $this->seed($fake);
        $this->importRecords($fake);
        $fake->engagements['calls']    = ['4001' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z'], 'contacts' => ['2001'], 'companies' => ['1001']]];
        $fake->engagements['meetings'] = ['5001' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z', 'hs_meeting_title' => 'M'], 'contacts' => ['2001', '2002']]];
        // Attended: a record the person may not view gets no subject row and no attendee; it is counted as skipped.
        $this->ctx()->denied = ['view:' . $this->target('1001'), 'view:' . $this->target('2002')];
        $id                  = $this->activities([HubSpotActivity::Call, HubSpotActivity::Meeting]);
        $this->finish($id);
        $call    = $this->target('4001', 'call');
        $meeting = $this->target('5001', 'meeting');
        self::assertSame([1, 1, 1, 2], [$this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$call]), $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$meeting]), $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$meeting]), $this->cursor($id)['skipped']]);
        // A re-run by a person who may view them adds them.
        $this->ctx()->denied = [];
        $again               = $this->activities([HubSpotActivity::Call, HubSpotActivity::Meeting]);
        $this->finish($again);
        self::assertSame([2, 2, 0], [$this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$call]), $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$meeting]), $this->cursor($again)['skipped']]);
        // Engagement pages ask for at most 25 items (audit ZDE-L2), whatever the page size.
        $limits = [];
        foreach ($fake->requests as [, $url]) {
            if (str_contains($url, '/crm/v3/objects/calls?')) {
                $limits[] = str_contains($url, 'limit=25');
            }
        }
        self::assertSame([true, true], $limits);
    }

    public function testUnattendedRunsSkipOtherOwnersRecordsAndPickAWritableOne(): void
    {
        $fake = $this->hubspot(self::ON + ['security' => ['resource_scoping' => true]]);
        $this->seed($fake);
        $this->importRecords($fake);
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET owner_id = 'leo' WHERE id = ?", [$this->target('2001')]);
        $this->connection()->executeStatement("UPDATE nowo_crm_company SET owner_id = 'leo'");
        $fake->engagements['calls'] = ['4001' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z'], 'contacts' => ['2001', '2002'], 'companies' => ['1001']]];
        $id                         = $this->activities([HubSpotActivity::Call]);
        $this->resume($id);
        self::assertSame(ImportStatus::Done, $this->imports()->preview($id)->status, 'no person needed: 2002 is writable');
        $call = $this->target('4001', 'call');
        self::assertSame($this->target('2002'), $this->text('SELECT subject_id FROM nowo_crm_engagement_subject WHERE engagement_id = ? AND is_primary = ?', [$call, true]));
        self::assertSame([1, 2], [$this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$call]), $this->cursor($id)['skipped']], 'leo\'s contact and company are left out');
    }

    public function testAnErasedContactsActivitiesNeverComeBack(): void
    {
        $fake = $this->hubspot(self::ON);
        $this->seed($fake);
        $this->importRecords($fake);
        $fake->engagements['notes'] = ['3001' => ['properties' => ['hs_note_body' => 'About Ada'], 'contacts' => ['2001']]];
        $fake->engagements['calls'] = [
            '4001' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z', 'hs_call_title' => 'Ada health'], 'contacts' => ['2001'], 'companies' => ['1001']],
            '4002' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z', 'hs_call_title' => 'Company only'], 'companies' => ['1001']],
        ];
        $fake->engagements['tasks'] = ['6001' => ['properties' => ['hs_timestamp' => '2026-01-01T00:00:00Z', 'hs_task_subject' => 'Call Ada'], 'contacts' => ['2001']]];
        $this->finish($this->activities([HubSpotActivity::Note, HubSpotActivity::Call, HubSpotActivity::Task]));
        self::assertSame([1, 2, 1], [$this->int('SELECT COUNT(*) FROM nowo_crm_note'), $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'), $this->int('SELECT COUNT(*) FROM nowo_crm_next_action')]);
        $this->service(CrmPersonalDataInterface::class)->erase($this->target('2001'));
        self::assertSame(4, $this->int('SELECT COUNT(*) FROM nowo_crm_external_ref WHERE erased = ?', [true]), 'the contact and its note, call and task');
        // Even when HubSpot no longer associates the call with the person, its tombstone keeps it out.
        $fake->engagements['calls']['4001']['contacts'] = [];
        $fake->engagements['calls']['4009']             = ['properties' => ['hs_timestamp' => '2026-01-02T00:00:00Z', 'hs_call_title' => 'New with Ada'], 'contacts' => ['2001'], 'companies' => ['1001']];
        $view                                           = $this->finish($this->activities([HubSpotActivity::Note, HubSpotActivity::Call, HubSpotActivity::Task]));
        self::assertSame(['created' => 0, 'updated' => 1, 'duplicate' => 4, 'invalid' => 0, 'limit' => 0], $view->counts);
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_engagement WHERE title LIKE '%Ada%'"));
        self::assertSame([0, 1, 0], [$this->int('SELECT COUNT(*) FROM nowo_crm_note'), $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'), $this->int('SELECT COUNT(*) FROM nowo_crm_next_action')]);
    }

    public function testUnattendedRunsNeedAPersonForSomeoneElsesRecord(): void
    {
        $fake = $this->hubspot(self::ON + ['security' => ['resource_scoping' => true]]);
        $this->seed($fake);
        $this->importRecords($fake);
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET owner_id = 'leo'");
        $fake->engagements['notes'] = ['3001' => ['properties' => ['hs_note_body' => 'Hi'], 'contacts' => ['2001']]];
        $id                         = $this->activities([HubSpotActivity::Note]);
        $this->resume($id);
        $view = $this->imports()->preview($id);
        self::assertSame([NeedsPersonException::CODE, true, 0], [$view->errorCode, $view->awaitingPerson, $this->int('SELECT COUNT(*) FROM nowo_crm_note')]);
        // A person's "Continue" settles it with their own access.
        self::assertTrue($this->imports()->run($id));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));
        // An unattended re-run does not update an item on someone else's record either.
        $rerun = $this->activities([HubSpotActivity::Note]);
        $this->resume($rerun);
        self::assertSame(NeedsPersonException::CODE, $this->imports()->preview($rerun)->errorCode);
        self::assertTrue($this->imports()->run($rerun));
        // Unattended rows name no record.
        $fake->engagements['notes']['3002'] = ['properties' => ['hs_note_body' => 'Hi'], 'contacts' => ['2002']];
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET owner_id = 'ana'");
        $again = $this->activities([HubSpotActivity::Note]);
        $this->resume($again);
        self::assertSame([ImportStatus::Done, null, null], [$this->imports()->preview($again)->status, $this->imports()->preview($again)->rows[0]->recordId, $this->imports()->preview($again)->rows[1]->recordId]);
    }

    public function testTheSwitchesRefuseAtStartAndSkipAtRun(): void
    {
        $fake = $this->hubspot(['activities' => ['enabled' => true]]);
        $this->seed($fake);
        $this->connect($fake);
        try {
            $this->hubImports()->start(new HubSpotImportScope([], [], [HubSpotActivity::Task]));
            self::fail('tasks need pipeline.enabled');
        } catch (InvalidInputException $e) {
            self::assertSame(['activities' => 'error.hubspot_activities_off'], $e->errors);
        }
        // A stage the configuration no longer imports (switched off since the start) is skipped with one row.
        $id = $this->hubImports()->start(new HubSpotImportScope([], [], [HubSpotActivity::Note]));
        $this->stages($id, ['owners', 'notes', 'tasks']);
        $view = $this->confirmAndFinish($id);
        self::assertSame('import.hubspot.tasks_off', $view->rows[0]->message);
        self::assertEquals(['object' => 'task', 'id' => ''], $view->rows[0]->data, 'MySQL reorders JSON keys');
    }

    public function testWithoutActivitiesNothingIsOfferedOrRun(): void
    {
        $fake = $this->hubspot();
        $this->seed($fake);
        $this->connect($fake);
        try {
            $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [], [HubSpotActivity::Note]));
            self::fail('activities need activities.enabled');
        } catch (InvalidInputException $e) {
            self::assertSame(['activities' => 'error.hubspot_activities_off'], $e->errors);
        }
        $id = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Company]));
        $this->stages($id, ['owners', 'calls']);
        self::assertSame('import.hubspot.activities_off', $this->confirmAndFinish($id)->rows[0]->message);
        // The start page has no activities field.
        self::assertCount(0, $this->client->request('GET', '/crm/import/hubspot/new')->filter('input[name="crm_hubspot_start[activities][]"]'));
    }

    public function testTheStartPageOffersTheKindsTheConfigurationImports(): void
    {
        $fake = $this->hubspot(['activities' => ['enabled' => true]]);
        $this->seed($fake);
        $this->connect($fake);
        $page = $this->client->request('GET', '/crm/import/hubspot/new');
        self::assertSame(['notes', 'calls', 'meetings'], $page->filter('input[name="crm_hubspot_start[activities][]"]')->each(static fn ($i): string => (string) $i->attr('value')));
        self::assertStringContainsString('Meetings', $page->filter('[data-testid="crm-hubspot-start"]')->text());
        $php    = $page->filter('form#crm-hubspot-form')->form()->getPhpValues();
        $fields = is_array($php['crm_hubspot_start'] ?? null) ? $php['crm_hubspot_start'] : [];
        // Neither an object nor an activity: 422 on the objects.
        $this->client->request('POST', '/crm/import/hubspot/new', ['crm_hubspot_start' => ['objects' => [], 'activities' => []] + $fields]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        // Activities only: an import in preview with nothing to map.
        $this->client->request('POST', '/crm/import/hubspot/new', ['crm_hubspot_start' => ['objects' => [], 'activities' => ['notes']] + $fields]);
        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertStringContainsString('activity:notes', $this->text('SELECT columns FROM nowo_crm_import'));
    }

    public function testTooManyEngagementsStopTheImport(): void
    {
        $fake = $this->hubspot(self::ON + ['import' => ['hubspot' => ['max_records' => 3]]]);
        $this->seed($fake);
        $this->importRecords($fake);
        $fake->engagements['notes'] = ['3001' => ['properties' => ['hs_note_body' => 'a']], '3002' => ['properties' => ['hs_note_body' => 'b']], '3003' => ['properties' => ['hs_note_body' => 'c']], '3004' => ['properties' => ['hs_note_body' => 'd']]];
        $id                         = $this->activities([HubSpotActivity::Note]);
        $this->imports()->run($id);
        self::assertSame([ImportStatus::Failed, 'hubspot_too_many'], [$this->imports()->preview($id)->status, $this->imports()->preview($id)->errorCode]);
    }

    /**
     * One company (1001) and three contacts (2001–2003), the first two at the company.
     */
    private function seed(FakeHubSpot $fake): void
    {
        $fake->seedCompanies(1);
        $fake->seedContacts(3);
        $fake->associations = ['2001' => [['1001', true]], '2002' => [['1001', false]]];
    }

    /**
     * Connects and imports the companies and contacts.
     */
    private function importRecords(FakeHubSpot $fake): void
    {
        $this->connect($fake);
        $this->finish($this->startAndConfirm([HubSpotObject::Contact]));
    }

    /**
     * Starts and confirms an engagements-only import; its id.
     *
     * @param list<HubSpotActivity> $kinds
     */
    private function activities(array $kinds = [HubSpotActivity::Note, HubSpotActivity::Call, HubSpotActivity::Meeting, HubSpotActivity::Task]): string
    {
        $id     = $this->hubImports()->start(new HubSpotImportScope([], [], $kinds));
        $schema = $this->hubImports()->schema($id);
        self::assertSame([], $schema->properties, 'nothing to map');
        $this->hubImports()->confirm($id, new HubSpotMapping([]), DuplicatePolicy::Skip, $schema->version);

        return $id;
    }

    private function confirmAndFinish(string $id): ImportView
    {
        $schema = $this->hubImports()->schema($id);
        $this->hubImports()->confirm($id, new HubSpotMapping([]), DuplicatePolicy::Skip, $schema->version);

        return $this->finish($id);
    }

    private function finish(string $id): ImportView
    {
        for ($i = 0; $i < 30 && !$this->imports()->run($id, 5); ++$i) {
            // one request at a time
        }
        $view = $this->imports()->preview($id, 100);
        self::assertSame(ImportStatus::Done, $view->status, (string) $view->errorCode);

        return $view;
    }

    /**
     * The CRM id behind a HubSpot id (a record, or an engagement kind's item).
     */
    private function target(string $hid, ?string $kind = null): string
    {
        return $kind === null
            ? $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = ? AND object_type IN ('company', 'contact')", [$hid])
            : $this->text('SELECT target_id FROM nowo_crm_external_ref WHERE external_id = ? AND object_type = ?', [$hid, $kind]);
    }

    /**
     * The run cursor of an import.
     *
     * @return array<string, mixed>
     */
    private function cursor(string $id): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($this->text('SELECT run_cursor FROM nowo_crm_import WHERE id = ?', [$id]), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Rewrites the stages of an import in preview (a configuration changed since its start).
     *
     * @param list<string> $stages
     */
    private function stages(string $id, array $stages): void
    {
        $cursor           = $this->cursor($id);
        $cursor['stages'] = $stages;
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET run_cursor = ? WHERE id = ?', [json_encode($cursor, JSON_THROW_ON_ERROR), $id]);
    }

    private function resume(string $id): void
    {
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET claim_token = NULL, resume_after = ? WHERE id = ?', [$this->clock()->now()->format('Y-m-d H:i:s'), $id]);
        (new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:import:resume')))->execute([]);
    }
}
