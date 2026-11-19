<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;
use InvalidArgumentException;

use function preg_match;

/**
 * What the CRM asks a task port to create (0.3.0; the port's own VO, REQ-ARCH-001 rule 3): the tenant, the actor,
 * the CRM subject, the title (untrusted), the due time, the assignee, the dedupe key (64 hex: the same key must give
 * the same task), the channel, the host context of the call (an API token: the host re-applies the same token) and the
 * local path of the CRM record.
 */
final readonly class CrmTaskRequest
{
    /**
     * @throws InvalidArgumentException when the dedupe key or the back link is malformed
     */
    public function __construct(
        public TenantRef $tenant,
        public ActorRef $actor,
        public SubjectRef $subject,
        public string $title,
        public DateTimeImmutable $dueAt,
        public ?ActorRef $assignee,
        public string $dedupeKey,
        public string $channel,
        public ?object $hostContext,
        public ?string $backLink,
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/', $dedupeKey) !== 1 || ($backLink !== null && preg_match('#\A/(?!/)[^\s]*\z#', $backLink) !== 1)) {
            throw new InvalidArgumentException('Invalid task request.');
        }
    }
}
