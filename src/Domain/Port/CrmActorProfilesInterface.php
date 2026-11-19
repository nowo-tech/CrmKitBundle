<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\ActorProfile;

/**
 * Display data of the host's people by actor id, in one call per page (contract section 8.1, 0.2.1). The read side
 * of the actor resolver (`security.actor_resolver`).
 */
interface CrmActorProfilesInterface
{
    /**
     * The known people among `$ids`, keyed by id; each profile's ref must be exactly its key (checked with ===). Unknown
     * ids are left out (the CRM then asks {@see CrmActorDirectoryInterface::actor()}).
     *
     * @param list<string> $ids actor ids, distinct
     *
     * @return array<string, ActorProfile>
     */
    public function resolve(array $ids): array;
}
