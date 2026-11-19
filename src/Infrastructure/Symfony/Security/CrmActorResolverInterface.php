<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Port\CrmActorProfilesInterface;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How a signed-in person becomes the CRM's actor id (0.2.1): the id stored as owner, author, created_by / updated_by,
 * timeline actor and audit actor. The default {@see IdentifierActorResolver} keeps `getUserIdentifier()`; a host
 * whose identifier can change (an e-mail) registers its own service under `security.actor_resolver` and returns a
 * stable id (a user UUID). Also names people for the pages ({@see CrmActorProfilesInterface::resolve()}, one call per
 * page). Stateless: nothing may be kept between calls (worker mode).
 */
interface CrmActorResolverInterface extends CrmActorProfilesInterface
{
    /**
     * The actor id of a person: 1-128 bytes, exact (an id that fails {@see ExactId}
     * means "no actor").
     */
    public function idOf(UserInterface $user): string;
}
