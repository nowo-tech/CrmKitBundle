<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;

/**
 * One page of a keyset list (0.3.0): the cursor of the previous page (null: the first) and the page size (1..100).
 */
final readonly class CrmApiPageRequest
{
    public const MAX = 100;

    /**
     * @throws CrmApiRefusal `INPUT_002` (field `limit`)
     */
    public function __construct(public ?CrmApiCursor $cursor = null, public int $limit = 25)
    {
        if ($limit < 1 || $limit > self::MAX) {
            throw CrmApiRefusal::malformed('limit');
        }
    }
}
