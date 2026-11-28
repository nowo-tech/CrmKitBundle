<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\Port\ContactMailLogInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataExporterInterface;

use function array_values;

/**
 * {@see ContactMailLogInterface} on MailKit's tag-scoped ports (MailKit >= 2.8, audit ZDI-M1): only rows with the
 * tenant's tag ({@see CrmMailLogTag}) are read or erased, so another tenant's rows and the host's own mail to the same
 * address stay. Within the tag the rows of each address are deleted, or unlinked and masked when they have other
 * recipients. A value that is not an address MailKit accepts is skipped (the log cannot hold it). MailKit's
 * `SentMailLogTableMissingException` is not caught: the erasure fails (and the CRM's unit rolls back) rather than report
 * a person erased while their rows may remain. Stateless.
 */
final readonly class MailKitContactMailLog implements ContactMailLogInterface
{
    /**
     * Wires MailKit's tag-scoped erasure and export ports.
     */
    public function __construct(private SentMailTaggedPersonalDataEraserInterface $eraser, private SentMailTaggedPersonalDataExporterInterface $exporter)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function erase(TenantRef $tenant, array $addresses): void
    {
        $tag = CrmMailLogTag::of($tenant);
        foreach ($this->subjects($addresses) as $subject) {
            $this->eraser->eraseTagged($subject, $tag);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function export(TenantRef $tenant, array $addresses): array
    {
        $tag  = CrmMailLogTag::of($tenant);
        $rows = [];
        foreach ($this->subjects($addresses) as $subject) {
            foreach ($this->exporter->exportTagged($subject, $tag) as $row) {
                // A message to two of the contact's addresses is one row; the tag is the CRM's, not a template.
                $rows[$row['id']] ??= ['id' => $row['id'], 'roles' => $row['roles'], 'status' => $row['status'], 'subject' => $row['subject'], 'queued_at' => $row['queued_at'], 'sent_at' => $row['sent_at'], 'failed_at' => $row['failed_at']];
            }
        }

        return array_values($rows);
    }

    /**
     * The distinct subjects of the addresses MailKit accepts.
     *
     * @param list<string> $addresses
     *
     * @return list<SentMailPersonalDataSubject>
     */
    private function subjects(array $addresses): array
    {
        $subjects = [];
        foreach ($addresses as $address) {
            $subject = SentMailPersonalDataSubject::tryFromEmail($address);
            if ($subject instanceof SentMailPersonalDataSubject) {
                $subjects[$subject->email] ??= $subject;
            }
        }

        return array_values($subjects);
    }
}
