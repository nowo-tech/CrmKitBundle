<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * **Deprecated since 0.2.0** (removed in 0.3.0): wraps a host checker of 0.1.0 configured through the root key
 * `access_checker`, so it keeps answering the five permissions it knew: `create`, `delete`, `import` are asked as
 * `edit`, `export` and `erase` as `manage` ({@see CrmPermission::legacy()}). Use `security.access_checker`
 * ({@see CrmKitAccessCheckerInterface}) and `nowo_crm_kit.record_access_check` listeners instead. Stateless.
 */
final readonly class LegacyCrmAccessChecker implements CrmAccessCheckerInterface
{
    /**
     * Wires the host's checker.
     */
    public function __construct(private CrmAccessCheckerInterface $inner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        return $this->inner->isGranted($permission->legacy(), $tenant, $subject);
    }

    /**
     * {@inheritDoc}
     */
    public function currentActor(): ?ActorRef
    {
        return $this->inner->currentActor();
    }
}
