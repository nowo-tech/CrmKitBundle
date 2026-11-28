<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * What happened to a sent e-mail (0.13.0): the `email` activity logged for it, or null when the message was sent but
 * logging it failed (the failure is logged; the person is told).
 */
final readonly class MailOutcome
{
    /**
     * Wires the outcome.
     */
    public function __construct(public ?string $engagementId)
    {
    }

    /**
     * Whether the activity was logged.
     */
    public function logged(): bool
    {
        return $this->engagementId !== null;
    }
}
