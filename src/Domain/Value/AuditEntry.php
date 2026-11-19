<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;

/**
 * One audit line (contract section 14): an action key `crm.*`, the subject's id and counts or codes only — never
 * names, e-mails or note text. **(0.3.0)** A write through the API carries its {@see AuditOrigin}: the channel, the
 * token id and the token's display name (the one documented exception: a token name names a token, not a person).
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
        public ?AuditOrigin $origin = null,
    ) {
    }
}
