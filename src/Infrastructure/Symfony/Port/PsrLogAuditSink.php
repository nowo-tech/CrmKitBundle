<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default audit sink: one structured info line per entry (ids and counts only).
 */
final readonly class PsrLogAuditSink implements CrmAuditSinkInterface
{
    private LoggerInterface $logger;

    /**
     * Wires the logger.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Logs the entry.
     */
    public function record(AuditEntry $entry): void
    {
        $this->logger->info('CRM audit: ' . $entry->action, [
            'tenant'       => $entry->tenant->value,
            'actor'        => $entry->actor?->value,
            'subject_type' => $entry->subjectType,
            'subject_id'   => $entry->subjectId,
        ] + $entry->details);
    }
}
