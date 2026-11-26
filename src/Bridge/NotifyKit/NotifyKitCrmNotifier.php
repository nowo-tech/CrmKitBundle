<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\NotifyKit;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_values;

/**
 * {@see CrmNotifierInterface} on NotifyKit (0.11.0, NotifyKit >= 0.3 installed, as is): one `notify()` per CRM
 * notification, personal (no NotifyKit context), the recipients and the actor as `RecipientRef(<recipient_type>, <actor
 * id>)`, no params (the title is the type's: "‹actor› mentioned you in a CRM note"; NotifyKit resolves the actor's name at
 * display time), the record's page as the local URL (its own access check runs when it is opened) and the mentioned
 * people as `involved`. NotifyKit skips the actor and writes after the CRM's commit (the kit calls it from an
 * after-commit listener). Stateless.
 */
final readonly class NotifyKitCrmNotifier implements CrmNotifierInterface
{
    /**
     * @param string $recipientType NotifyKit's recipient type of the CRM's actor ids (`activities.notify_kit.recipient_type`)
     */
    public function __construct(private NotifierInterface $notifier, private UrlGeneratorInterface $urls, private string $recipientType = 'user')
    {
    }

    /**
     * Hands the notification to NotifyKit.
     */
    public function notify(CrmNotification $notification): void
    {
        $recipients = [];
        foreach ($notification->recipients as $actor) {
            $recipients[$actor->value] = new RecipientRef($this->recipientType, $actor->value);
        }
        if ($recipients === []) {
            return;
        }
        $recipients = array_values($recipients);
        $this->notifier->notify(new NotificationRequest(
            $notification->type,
            $recipients,
            new RecipientRef($this->recipientType, $notification->actor->value),
            null,
            $notification->target,
            $this->url($notification),
            [],
            involved: $recipients,
        ));
    }

    /**
     * The record's page, or the site root when the CRM's pages are off.
     */
    private function url(CrmNotification $notification): string
    {
        $subject = $notification->subject;
        try {
            return $subject->type === SubjectType::Deal
                ? $this->urls->generate('nowo_crm_kit_deal_show', ['id' => $subject->id->value])
                : $this->urls->generate('nowo_crm_kit_show', ['type' => $subject->type->segment(), 'id' => $subject->id->value]);
        } catch (RoutingException) {
            return '/';
        }
    }
}
