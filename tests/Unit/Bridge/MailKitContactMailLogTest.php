<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Bridge;

use Nowo\CrmKitBundle\Bridge\MailKit\CrmMailLogTag;
use Nowo\CrmKitBundle\Bridge\MailKit\MailKitContactMailLog;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\TestSentMailLog;
use Nowo\MailKitBundle\Log\SentMailLogTableMissingException;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function mb_strlen;
use function str_repeat;

/**
 * Audit ZDI-M1: the contact's addresses reach MailKit's tag-scoped erasure and export normalised, once each, with the
 * tenant's tag; another tenant's rows and the host's untagged rows for the same address stay; values that are not
 * addresses are skipped; a missing log table is not hidden.
 */
final class MailKitContactMailLogTest extends TestCase
{
    public function testTheTagIsStableShortAndPerTenant(): void
    {
        $acme = CrmMailLogTag::of(new TenantRef('acme'));
        self::assertSame($acme, CrmMailLogTag::of(new TenantRef('acme')));
        self::assertNotSame($acme, CrmMailLogTag::of(new TenantRef('Acme')));
        self::assertMatchesRegularExpression('/\Acrm:[0-9a-f]{32}\z/', $acme);
        self::assertMatchesRegularExpression('/\Acrm:[0-9a-f]{32}\z/', CrmMailLogTag::of(new TenantRef(str_repeat('é', 64))));
        self::assertLessThanOrEqual(MailHeaders::TAG_MAX, mb_strlen($acme));
    }

    public function testEraseAndExportOnlyTheTenantsRows(): void
    {
        $acme  = new TenantRef('acme');
        $tag   = CrmMailLogTag::of($acme);
        $other = CrmMailLogTag::of(new TenantRef('globex'));
        $log   = new TestSentMailLog();
        $log->log('ada@example.test', 'One', $tag);
        $log->log('old@example.test', 'Two', $tag);
        $log->log('ada@example.test', 'Other tenant', $other);
        $log->log('ada@example.test', 'Host');
        $log->log('bob@example.test', 'Other person', $tag);
        $bridge = new MailKitContactMailLog($log, $log);

        self::assertSame([], $bridge->export($acme, []));
        $rows = $bridge->export($acme, ['ADA@example.test', 'ada@example.test', 'old@example.test', 'not an address']);
        self::assertSame(['One', 'Two'], array_column($rows, 'subject'));
        self::assertSame(['id', 'roles', 'status', 'subject', 'queued_at', 'sent_at', 'failed_at'], array_keys($rows[0]));

        $bridge->erase($acme, []);
        self::assertSame([], $log->erased);
        $bridge->erase($acme, ['ADA@example.test', 'ada@example.test', 'old@example.test', "bad\naddress@example.test", 'not an address', '']);
        self::assertSame(['ada@example.test ' . $tag, 'old@example.test ' . $tag], $log->erased);
        self::assertSame(['ada@example.test', 'bob@example.test'], array_keys($log->rows));
        self::assertSame(['Other tenant', 'Host'], array_column($log->rows['ada@example.test'], 'subject'));
        self::assertSame([], $bridge->export($acme, ['ada@example.test']));
    }

    public function testAMissingLogTableFailsTheErasureAndTheExport(): void
    {
        $log          = new TestSentMailLog();
        $log->missing = true;
        try {
            (new MailKitContactMailLog($log, $log))->export(new TenantRef('acme'), ['ada@example.test']);
            self::fail('no exception');
        } catch (SentMailLogTableMissingException) {
        }
        $this->expectException(SentMailLogTableMissingException::class);
        (new MailKitContactMailLog($log, $log))->erase(new TenantRef('acme'), ['ada@example.test']);
    }
}
