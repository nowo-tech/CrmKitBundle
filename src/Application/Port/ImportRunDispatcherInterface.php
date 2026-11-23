<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Hands an import run to the background (0.10.0, `import.messenger.enabled`, REQ-MSG-001): the Messenger
 * implementation dispatches `RunCrmImport` (ids only), delayed until `$notBefore` when given.
 *
 * @internal
 */
interface ImportRunDispatcherInterface
{
    /**
     * Asks the background to run the import.
     */
    public function dispatch(TenantRef $tenant, string $importId, ?DateTimeImmutable $notBefore = null): void;
}
