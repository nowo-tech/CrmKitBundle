<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;

use function preg_match;

/**
 * An opaque keyset cursor (0.3.0): at most 512 base64url characters, decoded only by the cursor codec.
 */
final readonly class CrmApiCursor
{
    /**
     * @throws CrmApiRefusal `INPUT_003`
     */
    public function __construct(public string $value)
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $value) !== 1) {
            throw CrmApiRefusal::of(CrmApiCode::INPUT_CURSOR);
        }
    }
}
