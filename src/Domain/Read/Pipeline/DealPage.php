<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

/**
 * One keyset page of deals (0.3.0): the deals and the key after the last one (null on the last page). The key is
 * `{v: sort value|null, id}`; the API seals it in its cursor.
 */
final readonly class DealPage
{
    /**
     * @param list<DealView> $deals
     * @param array{v: string|null, id: string}|null $next
     */
    public function __construct(public array $deals, public ?array $next)
    {
    }
}
