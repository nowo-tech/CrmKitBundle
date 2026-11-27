<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;

use function preg_match;

/**
 * A notification the CRM asks the host to deliver (0.11.0, {@see CrmNotifierInterface}):
 * a type (`crm.mention`), the members to notify (already checked: same tenant, may view the record), the actor, the
 * record it is about and a target that groups repeats. It never carries the record's name or the note's text: the
 * notification reads "‹actor› mentioned you in a CRM note" and links to the record, whose page checks access again.
 */
final readonly class CrmNotification
{
    /** The type of a mention in a note. */
    public const MENTION = 'crm.mention';

    /**
     * @param list<ActorRef> $recipients
     * @param string $target groups repeats, e.g. `crm-note:<id>` (1–128 of a-z, 0-9, `:`, `_`, `-`)
     *
     * @throws InvalidArgumentException a malformed type or target
     */
    public function __construct(
        public string $type,
        public TenantRef $tenant,
        public array $recipients,
        public ActorRef $actor,
        public SubjectRef $subject,
        public string $target,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.]{0,63}\z/D', $type) !== 1 || preg_match('/\A[a-z0-9][a-z0-9:_-]{0,127}\z/D', $target) !== 1) {
            throw new InvalidArgumentException('Invalid notification type or target.');
        }
    }
}
