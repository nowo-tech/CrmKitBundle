<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\ComposedText;
use Nowo\CrmKitBundle\Domain\Read\EmailTemplateView;

/**
 * The person's e-mail templates and snippets (0.13.0, `templates.enabled`), stored by MailKit (>= 2.7) for the call's
 * tenant and person: personal rows (only their owner sees them) and the organisation's shared rows (`manage` to change
 * them), with the CRM's consent purpose per template. Rows the person may not see read as missing (404).
 */
interface EmailTemplatesInterface
{
    /**
     * The rows the person may see, personal first, then shared, by key and locale.
     *
     * @throws NotAvailableException
     *
     * @return list<EmailTemplateView>
     */
    public function list(?EmailTemplateKind $kind = null): array;

    /**
     * One row with its text.
     *
     * @throws NotAvailableException
     */
    public function get(string $id): EmailTemplateView;

    /**
     * Stores a new row and, for a template, its consent classification; its id.
     *
     * @throws AccessDeniedException|InvalidInputException|NotAvailableException
     */
    public function create(EmailTemplateInput $input): string;

    /**
     * Changes a row shown at `$expectedVersion` and, for a template, its classification (kind and scope never change).
     *
     * @throws AccessDeniedException|ConflictException|InvalidInputException|NotAvailableException
     */
    public function update(string $id, EmailTemplateInput $input, int $expectedVersion): void;

    /**
     * Deletes a row shown at `$expectedVersion` (its classification goes with it).
     *
     * @throws AccessDeniedException|ConflictException|NotAvailableException
     */
    public function delete(string $id, int $expectedVersion): void;

    /**
     * Renders a row for a contact the person may view (and, optionally, one of the person's visible deals): the
     * `contact.*`, `company.*` (the contact's primary company), `deal.*` and `me.*` placeholders.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function compose(string $id, string $contactId, ?string $dealId = null): ComposedText;

    /**
     * The placeholders an editor may offer (`contact.first_name`, …), by namespace.
     *
     * @return array<string, list<string>>
     */
    public function placeholders(): array;
}
