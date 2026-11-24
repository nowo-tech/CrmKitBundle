<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Http;

use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;

/**
 * Worker mode (contract section 16, REQ-RUNTIME-002): clears the bundle's entity manager at the end of every request
 * and console command, and after every Messenger message a worker handled or failed (0.10.1, with
 * `import.messenger.enabled`), and resets it when a failure closed it, so no managed entity and no broken manager
 * survive into the next request or message of a kept-alive process.
 */
final readonly class EntityManagerHygieneListener
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * `kernel.terminate`, `kernel.finish_request` (main request), `console.terminate` and the Messenger worker's
     * `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`.
     */
    public function clear(): void
    {
        $this->runner->release($this->runner->manager()); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }
}
