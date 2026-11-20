<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

/**
 * An input of a write (0.3.0): its canonical form is the idempotency fingerprint (keys sorted by the store, lists in
 * their order, the idempotency key left out).
 */
interface CrmApiInput
{
    /**
     * The input as scalars and arrays only.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array;
}
