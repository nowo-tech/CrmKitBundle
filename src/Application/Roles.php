<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RolesChanged;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;

use function implode;
use function in_array;

/**
 * Sets the party roles of a record inside a running unit (contract sections 6.8 and 15): set semantics over the
 * configured roles only (a stored role that is no longer configured is never added nor removed here); the unique
 * `(tenant, record, role)` key makes a racing duplicate a replay, never a second row. The caller has locked the record
 * (or checks its version). Stateless.
 *
 * @internal
 */
final readonly class Roles
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private PartyCatalogue $catalogue)
    {
    }

    /**
     * Replaces the record's configured roles with `$keys` (already checked with {@see PartyCatalogue::checkRoles()});
     * the added and removed keys. With `$effects`, the change is audited (keys only) and announced after the commit.
     *
     * @param list<string> $keys
     *
     * @return array{list<string>, list<string>}
     */
    public function set(CrmContext $context, SubjectType $type, string $id, array $keys, ?Effects $effects = null): array
    {
        $store   = $this->support->store;
        $current = [];
        $removed = [];
        foreach ($store->roleAssignments($context->tenant, $type, [$id]) as $row) {
            $current[$row->getRoleKey()] = true;
            if ($this->catalogue->hasRole($row->getRoleKey()) && !in_array($row->getRoleKey(), $keys, true)) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $removed[] = $row->getRoleKey();
            }
        }
        $added = [];
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $store->add(new RoleAssignment($this->support->id(), $context->tenant->value, $type, $id, $key, $context->now, $context->actor->value)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $added[] = $key;
            }
        }
        if ($added === [] && $removed === []) {
            return [[], []];
        }
        $this->support->activity($context, $type, $id, ActivityKind::RolesChanged, ['added' => implode(',', $added), 'removed' => implode(',', $removed)]);
        if ($effects instanceof Effects) {
            $effects->event(new RolesChanged($context->tenant, $context->actor, $context->now, $type, $id, $added, $removed));
            $this->support->audit($effects, $context, 'crm.roles.changed', $type, $id, ['added' => implode(',', $added), 'removed' => implode(',', $removed)]);
        }

        return [$added, $removed];
    }
}
