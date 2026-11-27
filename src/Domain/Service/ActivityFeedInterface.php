<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\EngagementView;
use Nowo\CrmKitBundle\Domain\Read\Feed;
use Nowo\CrmKitBundle\Domain\Value\TimelineFilter;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;

/**
 * The activity feed of a record (0.11.0, `activities.enabled`), `view`: its notes, the calls, meetings and e-mails
 * logged on it or associated with it, and the host's items, filtered by kind and person, newest first. An activity
 * shows only while the viewer may view its primary record too.
 */
interface ActivityFeedInterface
{
    /**
     * A page of the feed of a company, a contact or a deal.
     */
    public function feed(SubjectType $type, string $id, TimelineFilter $filter, TimelineWindow $window): Feed;

    /**
     * One activity (its primary record must be viewable).
     */
    public function engagement(string $id): EngagementView;
}
