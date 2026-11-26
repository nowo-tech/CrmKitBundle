<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Port\CrmHostActivitySourceInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\CrmKitBundle\Domain\Value\HostActivityItem;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;

/**
 * 0.11.0: the test host's activity source (the items a test sets, as given: the kit must drop the wrong ones) and its
 * notifier (records what the kit hands over).
 */
final class TestActivitySources implements CrmHostActivitySourceInterface, CrmNotifierInterface
{
    /** @var list<HostActivityItem> */
    public array $items = [];

    /** @var list<array{string, string}> tenant and viewer of each call */
    public array $asked = [];

    /** @var list<CrmNotification> */
    public array $sent = [];

    /**
     * The items set by the test.
     */
    public function items(TenantRef $tenant, ActorRef $viewer, SubjectRef $subject, TimelineWindow $window): array
    {
        $this->asked[] = [$tenant->value, $viewer->value];

        return $this->items;
    }

    /**
     * Records the notification.
     */
    public function notify(CrmNotification $notification): void
    {
        $this->sent[] = $notification;
    }
}
