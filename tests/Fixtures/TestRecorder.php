<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Closure;
use Nowo\CrmKitBundle\Domain\Event\CrmEvent;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use ReflectionClass;

use function array_map;

/**
 * Collects the audit entries and the events the CRM releases after its commits.
 */
final class TestRecorder implements CrmAuditSinkInterface
{
    /** @var list<AuditEntry> */
    public array $audit = [];

    /** @var list<CrmEvent> */
    public array $events = [];

    /** @var (Closure(AuditEntry): void)|null called after each entry (a test acting at that moment) */
    public ?Closure $onRecord = null;

    public function record(AuditEntry $entry): void
    {
        $this->audit[] = $entry;
        if ($this->onRecord instanceof Closure) {
            ($this->onRecord)($entry);
        }
    }

    public function onEvent(CrmEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (AuditEntry $e): string => $e->action, $this->audit);
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn (CrmEvent $e): string => (new ReflectionClass($e))->getShortName(), $this->events);
    }
}
