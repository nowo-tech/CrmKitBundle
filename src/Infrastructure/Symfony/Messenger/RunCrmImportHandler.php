<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Application\Port\ImportRunDispatcherInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Handles {@see RunCrmImport} (0.10.0): claims the import like the resume command, runs up to `import.messenger.units`
 * chunks (HubSpot: pages) unattended, then dispatches the next message (now when more is left, delayed until
 * `resume_after` when the import paused). It never throws for a paused import, so no Messenger retry is needed.
 * Stateless (worker safe).
 */
final readonly class RunCrmImportHandler
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private ImportService $imports, private ImportRunDispatcherInterface $dispatcher, private int $units = 20)
    {
    }

    /**
     * Runs the import's next units.
     */
    public function __invoke(RunCrmImport $message): void
    {
        try {
            $tenant = new TenantRef($message->tenantId);
        } catch (InvalidArgumentException) {
            return;
        }
        [$state, $at] = $this->imports->runQueued($tenant, $message->importId, $this->units);
        if ($state === 'more' || ($state === 'wait' && $at !== null)) {
            $this->dispatcher->dispatch($tenant, $message->importId, $state === 'wait' ? $at : null);
        }
    }
}
