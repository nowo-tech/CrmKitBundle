<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Shared;

use Nowo\CrmKitBundle\Domain\Event\CrmEvent;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The side effects of one call (contract sections 14 and 15): events and audit entries collected inside the write unit
 * and released only after its commit. A local buffer, never service state; every attempt of the unit starts with
 * {@see self::clear()}, so a replay or a rollback drops what the failed attempt collected.
 */
final class Effects
{
    /** @var list<CrmEvent> */
    private array $events = [];

    /** @var list<AuditEntry> */
    private array $audit = [];

    /**
     * Keeps an event for after the commit.
     */
    public function event(CrmEvent $event): void
    {
        $this->events[] = $event; // @igor-ignore - per-call buffer, never a shared service
    }

    /**
     * Keeps an audit entry for after the commit.
     */
    public function audit(AuditEntry $entry): void
    {
        $this->audit[] = $entry; // @igor-ignore - per-call buffer, never a shared service
    }

    /**
     * Forgets everything (an attempt starts empty).
     */
    public function clear(): void
    {
        $this->events = []; // @igor-ignore - per-call buffer, never a shared service
        $this->audit  = []; // @igor-ignore - per-call buffer, never a shared service
    }

    /**
     * Releases the effects after the commit: audit entries first, then events.
     */
    public function release(?EventDispatcherInterface $dispatcher, CrmAuditSinkInterface $sink): void
    {
        [$events, $audit] = [$this->events, $this->audit];
        $this->clear();
        foreach ($audit as $entry) {
            $sink->record($entry);
        }
        foreach ($events as $event) {
            $dispatcher?->dispatch($event);
        }
    }
}
