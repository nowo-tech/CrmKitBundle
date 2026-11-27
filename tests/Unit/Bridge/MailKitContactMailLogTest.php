<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Bridge;

use Nowo\CrmKitBundle\Bridge\MailKit\MailKitContactMailLog;
use Nowo\CrmKitBundle\Tests\Fixtures\TestSentMailLog;
use Nowo\MailKitBundle\Log\SentMailLogTableMissingException;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * Audit ZDI-M1: the contact's addresses reach MailKit's sent-mail log erasure normalised, once each; values that are not
 * addresses are skipped; a missing log table is not hidden.
 */
final class MailKitContactMailLogTest extends TestCase
{
    public function testEraseByNormalisedAddress(): void
    {
        $log = new TestSentMailLog();
        $log->log('ada@example.test', 'One');
        $log->log('old@example.test', 'Two');
        $log->log('bob@example.test', 'Other');
        $bridge = new MailKitContactMailLog($log);

        $bridge->erase([]);
        self::assertSame([], $log->erased);
        $bridge->erase(['ADA@example.test', 'ada@example.test', 'old@example.test', "bad\naddress@example.test", 'not an address', '']);
        self::assertSame(['ada@example.test', 'old@example.test'], $log->erased);
        self::assertSame(['bob@example.test'], array_keys($log->rows));
    }

    public function testAMissingLogTableFailsTheErasure(): void
    {
        $log          = new TestSentMailLog();
        $log->missing = true;
        $this->expectException(SentMailLogTableMissingException::class);
        (new MailKitContactMailLog($log))->erase(['ada@example.test']);
    }
}
