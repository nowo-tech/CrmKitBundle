<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordAccessCheckEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Decisions on one company or contact, after the area check: computes the default and dispatches
 * {@see CrmKitEvents::RECORD_ACCESS_CHECK} so the host can grant or deny ({@see RecordAccessCheckEvent}).
 *
 * The default: with `security.resource_scoping: false` (the default) every record of the tenant is open to whoever
 * passed the area check (a shared CRM, as 0.1.0). With `true`, a record is open by default only when it has no owner,
 * when the person is its owner, or when they hold a `security.settings_roles` role; a team rule is the host's listener.
 * Stateless.
 */
final readonly class CrmResourceAccessChecker
{
    /**
     * @param list<string> $settingsRoles roles that see every record when scoping is on
     */
    public function __construct(
        private ?EventDispatcherInterface $events = null,
        private ?AuthorizationCheckerInterface $authorization = null,
        private array $settingsRoles = ['ROLE_ADMIN'],
        private bool $scoping = false,
    ) {
    }

    /**
     * Whether the person may do `$action` on the record.
     */
    public function isGranted(?object $user, ?ActorRef $actor, TenantRef $tenant, CrmPermission $action, SubjectRef $subject): bool
    {
        $owner   = $subject->owner?->value;
        $default = !$this->scoping || $owner === null || ($actor instanceof ActorRef && $owner === $actor->value) || $this->bypass($user);
        $event   = new RecordAccessCheckEvent($user, $actor?->value, $tenant->value, $subject->type->value, $subject->id->value, $owner, $action->value, $default);
        $this->events?->dispatch($event, CrmKitEvents::RECORD_ACCESS_CHECK);

        return $event->isGranted();
    }

    private function bypass(?object $user): bool
    {
        if ($user === null || !$this->authorization instanceof AuthorizationCheckerInterface) {
            return false;
        }
        foreach ($this->settingsRoles as $role) {
            try {
                if ($this->authorization->isGranted($role)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }
}
