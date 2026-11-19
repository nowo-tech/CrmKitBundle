<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;

/**
 * The shape of every API write (0.3.0, design §8): a completed key with the same input is answered again before
 * anything else; otherwise the write runs with the idempotency claim as the first statement of its unit and the
 * outcome as its last; a claim that meets a concurrent writer's committed row replays it. Stateless.
 *
 * @internal
 */
final readonly class CrmApiWriter
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmApiGuard $guard, private CrmApiIdempotency $idempotency)
    {
    }

    /**
     * Runs one write.
     *
     * @param array<string, mixed> $canonical the input's canonical form (the fingerprint)
     * @param int $status the HTTP status of a first answer (201 for a creation)
     * @param callable(mixed): array<string, string> $ids the ids of the unit's result
     * @param callable(CrmApiCall): CrmApiWritten $work the checks and the write, with the hooks bound
     * @param callable(CrmApiReplay): CrmApiWritten $replay the answer from stored ids (re-read through the read path)
     */
    public function write(CrmApiCall $call, IdempotencyKey $key, CrmApiOperation $operation, array $canonical, int $status, callable $ids, callable $work, callable $replay): CrmApiWritten
    {
        $stored = $this->idempotency->stored($call->principal, $call->context->tenant, $operation, $key, $canonical);
        if ($stored instanceof CrmApiReplay) {
            return $replay($stored);
        }
        $bound = $this->guard->withHooks($call, $this->idempotency->hooks($call->principal, $call->context->tenant, $operation, $key, $canonical, $status, $ids));
        try {
            return $work($bound);
        } catch (CrmApiReplay $replayed) {
            return $replay($replayed);
        }
    }
}
