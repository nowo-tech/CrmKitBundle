<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotConnectionStatus;

/**
 * A tenant's HubSpot connection as shown (0.10.0): never the token, only its last four characters.
 */
final readonly class HubSpotConnectionView
{
    /** Kind of a pasted private app token. */
    public const PRIVATE_APP = 'private_app';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $id,
        public string $kind,
        public HubSpotConnectionStatus $status,
        public string $accountId,
        public string $label,
        public string $tokenLast4,
        public array $scopes,
        public DateTimeImmutable $validatedAt,
        public int $version,
    ) {
    }
}
