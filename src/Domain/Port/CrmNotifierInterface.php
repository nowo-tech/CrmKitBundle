<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\CrmNotification;

/**
 * Delivers the CRM's notifications (0.11.0): the NotifyKit bridge when NotifyKit is installed, else nothing (a no-op),
 * or the host's service (`activities.notifier`). Called after the commit of the write it is about; a failure is
 * logged by the kit and never undoes the write.
 */
interface CrmNotifierInterface
{
    /**
     * Delivers one notification to its recipients.
     */
    public function notify(CrmNotification $notification): void;
}
