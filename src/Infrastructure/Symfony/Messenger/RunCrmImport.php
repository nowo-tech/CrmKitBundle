<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger;

/**
 * Run an import in the background (0.10.0, `import.messenger.enabled`, REQ-MSG-001): ids only, immutable. Route it to
 * an async transport; redelivery is safe (the claim and the cursor decide).
 */
final readonly class RunCrmImport
{
    /**
     * Wires the ids.
     */
    public function __construct(public string $importId, public string $tenantId)
    {
    }
}
