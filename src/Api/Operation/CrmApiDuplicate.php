<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use RuntimeException;

/**
 * Thrown inside a create's write unit when the duplicate check found a visible active record (0.3.0): nothing is
 * written and the operation answers with that record.
 *
 * @internal
 */
final class CrmApiDuplicate extends RuntimeException
{
    /**
     * @param string $key the matched key: `email`, `tax_id`, `domain` or `name`
     */
    public function __construct(public readonly string $recordId, public readonly string $key)
    {
        parent::__construct('Duplicate.');
    }
}
