<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\MailKitBundle\Log\SentMailErasureResult;
use Nowo\MailKitBundle\Log\SentMailLogTableMissingException;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;

use function count;

use const COUNT_RECURSIVE;

/**
 * An in-memory MailKit sent-mail log for the CRM's tests (audit ZDI-M1): rows per normalised address, the erasure
 * port, and the erased subjects. `$missing` raises MailKit's missing-table exception.
 */
final class TestSentMailLog implements SentMailPersonalDataEraserInterface
{
    /** @var array<string, list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>> */
    public array $rows = [];

    /** @var list<string> */
    public array $erased = [];

    public bool $missing = false;

    /**
     * Logs a message to an address.
     */
    public function log(string $address, string $subject): void
    {
        $email                = SentMailPersonalDataSubject::fromEmail($address)->email;
        $this->rows[$email][] = ['id' => (string) (count($this->rows, COUNT_RECURSIVE) + 1), 'roles' => ['recipient'], 'status' => 'sent', 'template' => null, 'subject' => $subject, 'queued_at' => null, 'sent_at' => '2026-11-18T10:00:00+00:00', 'failed_at' => null];
    }

    public function erase(SentMailPersonalDataSubject $subject): SentMailErasureResult
    {
        $this->check();
        $this->erased[] = $subject->email;
        $deleted        = count($this->rows[$subject->email] ?? []);
        unset($this->rows[$subject->email]);

        return new SentMailErasureResult($deleted);
    }

    private function check(): void
    {
        if ($this->missing) {
            throw new SentMailLogTableMissingException(['mail_kit_sent_mail']);
        }
    }
}
