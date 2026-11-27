<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\TaskBoard;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Engagement\ActivityFeed;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\EngagementView;
use Nowo\TaskBoardBundle\Calendar\CalendarEvent;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEventSourceInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function mb_substr;

/**
 * The CRM's meetings on TaskBoard's calendar (0.11.0, TaskBoard >= 2.24, `activities.taskboard_calendar`): the meetings
 * the signed-in person logged or attends, on records they may view, not cancelled, as read-only timed events linked to
 * the record's page. The CRM's own access gate decides (tenant, feature gate, actor of the session); the events carry
 * the viewer's tenant key only when the board's tenant is the CRM's tenant (the same key, or a board without tenancy),
 * else the source returns nothing. Stateless: everything is resolved per call.
 */
final readonly class CrmMeetingCalendarSource implements CalendarEventSourceInterface
{
    /** The source key. */
    public const KEY = 'crm-meetings';

    /** Most events per calendar page. */
    public const MAX_EVENTS = 200;

    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmAccess $access, private ActivityFeed $feed, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * `crm-meetings`.
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * The viewer's meetings in the range.
     *
     * @return list<CalendarEvent>
     */
    public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable
    {
        try {
            $tenant = $this->access->base()->tenant;
            if ($viewer->tenantKey !== null && $viewer->tenantKey !== $tenant->value) {
                return [];
            }
            $meetings = $this->feed->meetings($range->from, $range->to, self::MAX_EVENTS);
        } catch (NotAvailableException|AccessDeniedException) {
            return [];
        }
        $events = [];
        foreach ($meetings as $meeting) {
            $events[] = $this->event($meeting, $viewer->tenantKey);
        }

        return $events;
    }

    /**
     * One event (the CRM's titles and labels are printable text, as TaskBoard requires).
     */
    private function event(EngagementView $meeting, ?string $tenantKey): CalendarEvent
    {
        $end   = $meeting->endsAt ?? $meeting->occurredAt;
        $title = $meeting->title ?? '';
        if ($meeting->subjectLabel !== null) {
            $title = $title === '' ? $meeting->subjectLabel : $title . ' · ' . $meeting->subjectLabel;
        }
        try {
            $url = $this->urls->generate('nowo_crm_kit_show', ['type' => $meeting->subjectType->segment(), 'id' => $meeting->subjectId]) . '#crm-activities';
        } catch (RoutingException) {
            $url = null;
        }

        return new CalendarEvent($meeting->id, mb_substr($title, 0, CalendarEvent::MAX_TITLE_LENGTH), $meeting->occurredAt, $end, url: $url, category: 'crm-meeting', tenantKey: $tenantKey);
    }
}
