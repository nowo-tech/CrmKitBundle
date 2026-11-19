<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * What a host panel provider gets for the 360° page (contract section 8.2).
 */
final readonly class PanelContext
{
    /**
     * @param list<HostObjectRef> $links the host objects linked to the subject
     */
    public function __construct(
        public TenantRef $tenant,
        public SubjectRef $subject,
        public ?ActorRef $actor,
        public array $links,
    ) {
    }
}
