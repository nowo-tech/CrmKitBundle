<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\Templates\Port\TemplateMailStoreInterface;
use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;

/**
 * Deletes the CRM's classification of a template MailKit deleted (0.13.0, listener of {@see TemplateDeletedEvent}):
 * one statement on the CRM's connection. MailKit dispatches the event inside its delete transaction (its contract C10):
 * when both share the connection the row goes in the same transaction, and a failure here rolls the delete back.
 * Stateless.
 */
final readonly class TemplatePurposeCleaner
{
    /**
     * Wires the store.
     */
    public function __construct(private TemplateMailStoreInterface $store)
    {
    }

    /**
     * Forgets the template's classification.
     */
    public function __invoke(TemplateDeletedEvent $event): void
    {
        $this->store->forget($event->tenant, $event->templateId);
    }
}
