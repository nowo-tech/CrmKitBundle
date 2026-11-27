<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\Port\ContactMailLogInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;

/**
 * {@see ContactMailLogInterface} on MailKit's `SentMailPersonalDataEraserInterface` (0.13.0, audit ZDI-M1): the rows of
 * each address are deleted, or unlinked and masked when they have other recipients. A value that is not an address
 * MailKit accepts is skipped (the log cannot hold it). MailKit's `SentMailLogTableMissingException` is not caught: the
 * erasure fails (and the CRM's unit rolls back) rather than report a person erased while their rows may remain.
 * Stateless.
 */
final readonly class MailKitContactMailLog implements ContactMailLogInterface
{
    /**
     * Wires MailKit's erasure port.
     */
    public function __construct(private SentMailPersonalDataEraserInterface $eraser)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function erase(array $addresses): void
    {
        $done = [];
        foreach ($addresses as $address) {
            $subject = SentMailPersonalDataSubject::tryFromEmail($address);
            if ($subject instanceof SentMailPersonalDataSubject && !isset($done[$subject->email])) {
                $done[$subject->email] = true;
                $this->eraser->erase($subject);
            }
        }
    }
}
