<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Port\ImportRunDispatcherInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function max;

/**
 * {@see ImportRunDispatcherInterface} on Symfony Messenger (0.10.0): dispatches {@see RunCrmImport}, with a
 * {@see DelayStamp} until `$notBefore`. Stateless.
 */
final readonly class MessengerImportRunDispatcher implements ImportRunDispatcherInterface
{
    /**
     * Wires the bus and the clock.
     */
    public function __construct(private MessageBusInterface $bus, private ClockInterface $clock)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function dispatch(TenantRef $tenant, string $importId, ?DateTimeImmutable $notBefore = null): void
    {
        $stamps = [];
        if ($notBefore instanceof DateTimeImmutable) {
            $stamps[] = new DelayStamp(max(0, ($notBefore->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000));
        }
        $this->bus->dispatch(new RunCrmImport($importId, $tenant->value), $stamps);
    }
}
