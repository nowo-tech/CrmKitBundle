<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\HostActivityItem;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;

/**
 * Optional host port (0.11.0, `activities.host_source`): the host's own entries on a record's activity feed (tasks,
 * time entries, linked e-mails, tickets, meetings already in a person's calendar...). The host returns only items the
 * viewer may see; the kit drops any item of another record than `$subject`, items outside the window, and keeps at most
 * the window's limit. Asked once per feed page, never cached (worker mode). Default: no items.
 */
interface CrmHostActivitySourceInterface
{
    /**
     * The viewer's items of the record, newest first, strictly before the window's position.
     *
     * @return list<HostActivityItem>
     */
    public function items(TenantRef $tenant, ActorRef $viewer, SubjectRef $subject, TimelineWindow $window): array;
}
