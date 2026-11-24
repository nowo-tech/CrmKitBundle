<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Application\Port\ImportRunDispatcherInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles {@see RunCrmImport} (0.10.0): claims the import like the resume command, runs up to `import.messenger.units`
 * chunks (HubSpot: pages) unattended, then dispatches the next message (now when more is left, delayed until
 * `resume_after` when the import paused). It never throws for a paused import, so no Messenger retry is needed.
 * Stateless (worker safe). 0.10.1 (REQ-MSG-001): declared with `#[AsMessageHandler]` (the kit still defines the
 * service and its tag itself) and logs the message type, the import id and the outcome; a message whose tenant id is
 * not valid is dropped with a warning.
 */
#[AsMessageHandler(handles: RunCrmImport::class)]
final readonly class RunCrmImportHandler
{
    /**
     * Wires the collaborators.
     *
     * @param LoggerInterface|null $logger message type, import id and outcome (ids only, no personal data)
     */
    public function __construct(private ImportService $imports, private ImportRunDispatcherInterface $dispatcher, private int $units = 20, private ?LoggerInterface $logger = null)
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
            $this->logger?->warning('nowo_crm_kit: import message dropped, its tenant id is not valid', ['message' => RunCrmImport::class, 'import' => $message->importId]);

            return;
        }
        [$state, $at] = $this->imports->runQueued($tenant, $message->importId, $this->units);
        $this->logger?->info('nowo_crm_kit: import message handled', ['message' => RunCrmImport::class, 'import' => $message->importId, 'outcome' => $state]);
        if ($state === 'more' || ($state === 'wait' && $at !== null)) {
            $this->dispatcher->dispatch($tenant, $message->importId, $state === 'wait' ? $at : null);
        }
    }
}
