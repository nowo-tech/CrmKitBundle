<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement;

use Nowo\CrmKitBundle\Domain\Event\NoteMentioned;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Hands the members mentioned in a note to the notifier (0.11.0), on {@see NoteMentioned}, which the kit dispatches only
 * after the note's commit. The notification names no record and no text (`crm.mention`, target `crm-note:<id>`); a
 * notifier failure is logged (warning, ids only) and never undoes the note. Stateless.
 */
final readonly class MentionNotifier
{
    private LoggerInterface $logger;

    /**
     * Wires the notifier.
     */
    public function __construct(private CrmNotifierInterface $notifier, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Notifies the mentioned members.
     */
    public function __invoke(NoteMentioned $event): void
    {
        if (!$event->actor instanceof ActorRef || $event->mentioned === []) {
            return;
        }
        try {
            $this->notifier->notify(new CrmNotification(CrmNotification::MENTION, $event->tenant, $event->mentioned, $event->actor, SubjectRef::of($event->type, $event->subjectId), 'crm-note:' . $event->noteId));
        } catch (Throwable $e) {
            $this->logger->warning('nowo_crm_kit: the mention notification of a note failed.', ['note' => $event->noteId, 'exception' => $e::class]);
        }
    }
}
