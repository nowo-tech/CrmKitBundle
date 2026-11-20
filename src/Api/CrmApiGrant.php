<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

/**
 * What an API token grants (0.3.0): a level and three separate ticks (amounts, consents, "agents may delete and
 * archive"). Each tick is also checked against the person's own permission ({@see CrmApiCapability}).
 */
final readonly class CrmApiGrant
{
    /**
     * @param bool $amounts `amounts.view`
     * @param bool $consents the consents gate
     * @param bool $destructive trash operations
     */
    public function __construct(
        public CrmApiLevel $level,
        public bool $amounts = false,
        public bool $consents = false,
        public bool $destructive = false,
    ) {
    }
}
