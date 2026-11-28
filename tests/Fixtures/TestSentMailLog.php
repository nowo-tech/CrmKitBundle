<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\MailKitBundle\Log\SentMailErasureResult;
use Nowo\MailKitBundle\Log\SentMailLogTableMissingException;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataExporterInterface;

use function array_filter;
use function array_values;
use function count;

/**
 * An in-memory MailKit sent-mail log for the CRM's tests (audit ZDI-M1): rows per normalised address with their tag,
 * MailKit 2.8's tag-scoped erasure and export ports (exact tag), and the erased subject and tag pairs. `$missing`
 * raises MailKit's missing-table exception.
 */
final class TestSentMailLog implements SentMailTaggedPersonalDataEraserInterface, SentMailTaggedPersonalDataExporterInterface
{
    /** @var array<string, list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null, error: string|null, html_body: string|null, text_body: string|null}>> */
    public array $rows = [];

    /** @var list<string> "address tag" pairs */
    public array $erased = [];

    public bool $missing = false;

    private int $next = 0;

    /**
     * Logs a message to an address, with a tag (null: untagged, as the host's own mail).
     */
    public function log(string $address, string $subject, ?string $tag = null): void
    {
        $email                = SentMailPersonalDataSubject::fromEmail($address)->email;
        $this->rows[$email][] = ['id' => (string) ++$this->next, 'roles' => ['recipient'], 'status' => 'sent', 'template' => $tag, 'subject' => $subject, 'queued_at' => null, 'sent_at' => '2026-11-18T10:00:00+00:00', 'failed_at' => null, 'error' => null, 'html_body' => null, 'text_body' => null];
    }

    public function eraseTagged(SentMailPersonalDataSubject $subject, string $tag): SentMailErasureResult
    {
        $tag = SentMailRecorder::tagFilter($tag);
        $this->check();
        $this->erased[]              = $subject->email . ' ' . $tag;
        $rows                        = $this->rows[$subject->email] ?? [];
        $kept                        = array_values(array_filter($rows, static fn (array $row): bool => $row['template'] !== $tag));
        $this->rows[$subject->email] = $kept;
        if ($kept === []) {
            unset($this->rows[$subject->email]);
        }

        return new SentMailErasureResult(count($rows) - count($kept));
    }

    public function exportTagged(SentMailPersonalDataSubject $subject, string $tag): array
    {
        $tag = SentMailRecorder::tagFilter($tag);
        $this->check();

        return array_values(array_filter($this->rows[$subject->email] ?? [], static fn (array $row): bool => $row['template'] === $tag));
    }

    private function check(): void
    {
        if ($this->missing) {
            throw new SentMailLogTableMissingException(['mail_kit_sent_mail']);
        }
    }
}
