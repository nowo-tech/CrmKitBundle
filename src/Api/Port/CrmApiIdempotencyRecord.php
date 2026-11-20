<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Port;

/**
 * A stored idempotency key (0.3.0): the operation, the fingerprint, the HTTP status of the first answer and the ids of
 * its result (null status: not completed, only possible inside the claiming unit).
 */
final readonly class CrmApiIdempotencyRecord
{
    /**
     * @param array<string, string> $resultIds
     */
    public function __construct(public string $operation, public string $fingerprint, public ?int $status, public array $resultIds)
    {
    }
}
