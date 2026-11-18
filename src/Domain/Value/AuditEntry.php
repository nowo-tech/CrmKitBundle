<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;

/**
 * One audit line (contract section 14): an action key `crm.*`, the subject's id and counts or codes only — never
 * names, e-mails or note text.
 */
final readonly class AuditEntry
{
    /**
     * @param string $action action key, e.g. `crm.company.created`
     * @param array<string, bool|int|string|null> $details ids, counts and codes
     */
    public function __construct(
        public TenantRef $tenant,
        public ?ActorRef $actor,
        public string $action,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $details,
        public DateTimeImmutable $at,
    ) {
    }
}
