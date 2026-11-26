<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;

/**
 * The default notifier without NotifyKit (0.11.0): delivers nothing.
 */
final readonly class NullCrmNotifier implements CrmNotifierInterface
{
    /**
     * Does nothing.
     */
    public function notify(CrmNotification $notification): void
    {
    }
}
