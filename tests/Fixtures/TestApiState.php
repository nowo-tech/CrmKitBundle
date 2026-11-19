<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Api\CrmApiPrincipal;

/**
 * The principal the HTTP tests act as.
 */
final class TestApiState
{
    public ?CrmApiPrincipal $principal = null;

    /** The authenticator throws (an unexpected failure). */
    public bool $fail = false;
}
