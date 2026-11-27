<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Engagement\IcsWriter;
use Nowo\CrmKitBundle\Application\Engagement\MentionNotifier;
use Nowo\CrmKitBundle\Application\Engagement\Mentions;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\NoteMentioned;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\CrmKitBundle\Domain\Value\IcsInvite;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\NoHostActivitySource;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\NullCrmNotifier;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ScopedRecordAudience;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function array_map;
use function explode;
use function is_string;
use function str_repeat;
use function strlen;

/**
 * 0.11.0: the ICS writer, the mention text helpers, the mention listener and the default ports.
 */
final class ActivityHelpersTest extends TestCase
{
    private const ID = '0190a000-0000-7000-8000-000000000001';

    public function testIcsWriterEscapesFoldsAndStaysInUtc(): void
    {
        $zone   = new DateTimeZone('Europe/Madrid');
        $start  = new DateTimeImmutable('2030-03-04 10:00', $zone);
        $title  = 'Review; budget, Q1' . "\n" . str_repeat('é', 60) . "\x07\u{202E}";
        $invite = new IcsInvite(self::ID . '@crm.example.test', 2, 'PUBLISH', $start, $start->modify('+90 minutes'), $title, 'Room \\4', 'https://crm.example.test/crm/contacts/' . self::ID, true, new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('UTC')));
        $ics    = IcsWriter::write($invite);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" . IcsWriter::PRODID . "\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n", $ics);
        self::assertStringContainsString("DTSTART:20300304T090000Z\r\nDTEND:20300304T103000Z\r\n", $ics);
        self::assertStringContainsString("DTSTAMP:20300301T080000Z\r\n", $ics);
        self::assertStringContainsString("SEQUENCE:2\r\n", $ics);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
        self::assertStringContainsString('SUMMARY:Review\; budget\\, Q1\\n', $ics);
        self::assertStringContainsString("LOCATION:Room \\\\4\r\n", $ics);
        self::assertStringNotContainsString("\x07", $ics);
        self::assertStringNotContainsString("\u{202E}", $ics);
        self::assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), $line);
        }
        $plain = IcsWriter::write(new IcsInvite('a@b', 0, 'REQUEST', $start, $start->modify('+1 hour'), 'Call', null, null, false, $start));
        self::assertStringContainsString("STATUS:CONFIRMED\r\n", $plain);
        self::assertStringNotContainsString('LOCATION', $plain);
        self::assertStringNotContainsString('URL:', $plain);
    }

    public function testMentionTextHelpers(): void
    {
        $h1 = 'u' . str_repeat('a', 24);
        $h2 = 'u' . str_repeat('b', 24);
        self::assertSame([$h1, $h2], Mentions::handles('Hi @' . $h1 . ', ping (@' . $h2 . ') and @' . $h1 . ' again; mail x@' . $h2 . ' @' . $h2 . 'x'));
        self::assertSame([], Mentions::handles('No @ana mention'));
        self::assertSame('Hi @Ana and @' . $h2, Mentions::display('Hi @' . $h1 . ' and @' . $h2, [$h1 => 'Ana']));
        self::assertSame('Hi @' . $h1, Mentions::display('Hi @' . $h1, []));
    }

    public function testMentionListener(): void
    {
        $notifier = new class implements CrmNotifierInterface {
            /** @var list<CrmNotification> */
            public array $sent = [];

            public bool $fail = false;

            public function notify(CrmNotification $notification): void
            {
                if ($this->fail) {
                    throw new RuntimeException('down');
                }
                $this->sent[] = $notification;
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (is_string($level) ? $level : '') . ': ' . $message;
            }
        };
        $listener = new MentionNotifier($notifier, $logger);
        $tenant   = new TenantRef('acme');
        $now      = new DateTimeImmutable('2030-03-04 10:00');
        $listener(new NoteMentioned($tenant, new ActorRef('ana'), $now, SubjectType::Contact, self::ID, 'n1', [new ActorRef('leo')]));
        self::assertCount(1, $notifier->sent);
        self::assertSame(['crm.mention', 'crm-note:n1', 'contact', 'leo'], [$notifier->sent[0]->type, $notifier->sent[0]->target, $notifier->sent[0]->subject->type->value, $notifier->sent[0]->recipients[0]->value]);
        $listener(new NoteMentioned($tenant, null, $now, SubjectType::Contact, self::ID, 'n1', [new ActorRef('leo')]));
        $listener(new NoteMentioned($tenant, new ActorRef('ana'), $now, SubjectType::Contact, self::ID, 'n1', []));
        self::assertCount(1, $notifier->sent, 'no actor or nobody: nothing sent');
        $notifier->fail = true;
        $listener(new NoteMentioned($tenant, new ActorRef('ana'), $now, SubjectType::Contact, self::ID, 'n2', [new ActorRef('leo')]));
        self::assertSame(['warning: nowo_crm_kit: the mention notification of a note failed.'], $logger->lines);
        (new MentionNotifier($notifier))(new NoteMentioned($tenant, new ActorRef('ana'), $now, SubjectType::Contact, self::ID, 'n3', [new ActorRef('leo')]));
    }

    public function testDefaultPorts(): void
    {
        $tenant = new TenantRef('acme');
        $owned  = SubjectRef::of(SubjectType::Contact, self::ID, 'leo');
        $free   = SubjectRef::of(SubjectType::Contact, self::ID);
        $people = [new ActorRef('ana'), new ActorRef('leo')];
        self::assertSame($people, (new ScopedRecordAudience())->viewers($tenant, $owned, $people));
        self::assertSame($people, (new ScopedRecordAudience(true))->viewers($tenant, $free, $people));
        self::assertSame(['leo'], array_map(static fn (ActorRef $a): string => $a->value, (new ScopedRecordAudience(true))->viewers($tenant, $owned, $people)));
        self::assertSame([], (new NoHostActivitySource())->items($tenant, new ActorRef('ana'), $owned, new TimelineWindow()));
        (new NullCrmNotifier())->notify(new CrmNotification(CrmNotification::MENTION, $tenant, $people, new ActorRef('ana'), $owned, 't'));
        $this->addToAssertionCount(1);
    }
}
