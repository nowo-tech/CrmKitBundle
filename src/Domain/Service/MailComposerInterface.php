<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Exception\RateLimitedException;
use Nowo\CrmKitBundle\Domain\Read\MailOutcome;

/**
 * *Write e-mail* (0.13.0, `templates.enabled`): sends a message to a contact the person may edit and logs it as an
 * `email` activity on the contact. A template's consent purpose must be granted by the contact (a service template or
 * a message without a template needs none); an unclassified template is refused. Per-person rate limit; one send per
 * send key.
 */
interface MailComposerInterface
{
    /**
     * Sends the draft; the logged activity.
     *
     * @throws AccessDeniedException|NotAvailableException
     * @throws InvalidInputException `to` (no address), `template` (unclassified, no consent, not visible), `subject`, `text`, `_` (not sent)
     * @throws ConflictException crm_mail_already_sent (the send key was used), crm_record_trashed
     * @throws RateLimitedException
     */
    public function send(MailDraft $draft): MailOutcome;
}
