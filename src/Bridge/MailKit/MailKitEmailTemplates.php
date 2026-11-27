<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\Templates\Port\TemplateMailStoreInterface;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\ComposedText;
use Nowo\CrmKitBundle\Domain\Read\EmailTemplateView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplatesInterface;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TemplateRef;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Exception\TemplateVersionConflict;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Nowo\MailKitBundle\Template\Value\RenderedTemplate;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Template\Value\TemplateSummary;

use function array_key_exists;
use function array_map;
use function array_values;
use function in_array;
use function trim;

/**
 * {@see EmailTemplatesInterface} on MailKit's template library and renderer (0.13.0): every call acts as the call's CRM
 * tenant and person (`edit` tenant-wide; MailKit's permission port decides personal / shared, `manage` for shared rows
 * with {@see CrmTemplatePermission}), MailKit's exceptions become the CRM's (missing ⇒ 404, refused ⇒ 403, stale ⇒ 409,
 * input errors per field), and a template's consent classification is written after MailKit stored it, in a CRM unit
 * with its audit entry. Stateless.
 */
final readonly class MailKitEmailTemplates implements EmailTemplatesInterface
{
    /** MailKit's input fields with their own message (`template_error.<field>`). */
    public const FIELDS = ['key', 'locale', 'name', 'subject', 'body', 'html', 'placeholders', 'scope', 'output'];

    /**
     * @param list<string> $purposes the configured consent purposes (`gdpr.purposes`)
     */
    public function __construct(
        private CrmTemplateActors $actors,
        private TemplateLibraryInterface $library,
        private TemplateRendererInterface $renderer,
        private TemplateMailStoreInterface $store,
        private WriteSupport $support,
        private CrmReaderInterface $reader,
        private array $purposes,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function list(?EmailTemplateKind $kind = null): array
    {
        $context = $this->context();
        $actor   = $this->actors->actor($context);
        $rows    = [];
        foreach ($kind instanceof EmailTemplateKind ? [TemplateKind::from($kind->value)] : [TemplateKind::Template, TemplateKind::Snippet] as $mailKind) {
            foreach ($this->library->list($actor, $mailKind) as $summary) {
                $rows[] = $summary;
            }
        }
        $ids     = array_map(static fn (TemplateSummary $s): string => $s->id, $rows);
        $classes = $this->support->read(fn (): array => $this->store->classifications($context->tenant, $ids)); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $manage  = $this->actors->access()->can(CrmPermission::Manage);

        return array_map(fn (TemplateSummary $s): EmailTemplateView => $this->view($s, '', false, $classes, $manage), $rows);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $id): EmailTemplateView
    {
        $context = $this->context();
        $stored  = $this->stored($context, $id);
        $classes = $this->support->read(fn (): array => $this->store->classifications($context->tenant, [$stored->id])); // @igor-ignore - the runner calls this closure at once and keeps nothing

        return $this->view($stored->summary(), $stored->text, trim($stored->html) !== '', $classes, $this->actors->access()->can(CrmPermission::Manage));
    }

    /**
     * {@inheritDoc}
     */
    public function create(EmailTemplateInput $input): string
    {
        $context = $this->context();
        $purpose = $this->purpose($input);
        $stored  = $this->call(fn (): StoredTemplate => $this->library->create($this->actors->actor($context), $this->draft($input))); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $this->record($context, $stored, $purpose, 'crm.template.created');

        return $stored->id;
    }

    /**
     * {@inheritDoc}
     */
    public function update(string $id, EmailTemplateInput $input, int $expectedVersion): void
    {
        $context = $this->context();
        $current = $this->stored($context, $id);
        if ($current->kind->value !== $input->kind->value) {
            throw InvalidInputException::field('kind', 'template_error.kind');
        }
        if (($current->scope === TemplateScope::Shared) !== $input->shared) {
            throw InvalidInputException::field('shared', 'template_error.scope');
        }
        $purpose = $this->purpose($input);
        $stored  = $this->call(fn (): StoredTemplate => $this->library->update($this->actors->actor($context), $current->id, $expectedVersion, $this->draft($input)), $current->id); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $this->record($context, $stored, $purpose, 'crm.template.saved');
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $id, int $expectedVersion): void
    {
        $context = $this->context();
        $current = $this->stored($context, $id);
        // MailKit's TemplateDeletedEvent removes the classification (TemplatePurposeCleaner) inside its delete.
        $this->call(fn (): bool => $this->deleted($context, $current->id, $expectedVersion), $current->id); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $this->support->write(function (Effects $effects) use ($context, $current): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->audit($effects, $context, 'crm.template.deleted', null, null, ['template' => $current->id, 'kind' => $current->kind->value, 'shared' => $current->scope === TemplateScope::Shared]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function compose(string $id, string $contactId, ?string $dealId = null): ComposedText
    {
        $context = $this->context();
        if (!TemplateRef::isValid($id)) {
            throw new NotAvailableException();
        }
        $contact = $this->reader->record(SubjectType::Contact, $contactId);
        if ($contact->status !== RecordStatus::Active) {
            throw new NotAvailableException();
        }
        $subjects = ['contact' => $contact->id];
        foreach ($contact->affiliations as $affiliation) {
            if ($affiliation->primary) {
                $subjects['company'] = $affiliation->companyId;
            }
        }
        if ($dealId !== null && RecordId::isValid($dealId)) {
            $subjects['deal'] = $dealId;
        }
        $rendered = $this->call(fn (): RenderedTemplate => $this->renderer->render($this->actors->actor($context), $id, $subjects), $id); // @igor-ignore - the runner calls this closure at once and keeps nothing

        return new ComposedText($rendered->kind === TemplateKind::Template ? $rendered->subject : '', $rendered->text, array_values($rendered->unresolved));
    }

    /**
     * {@inheritDoc}
     */
    public function placeholders(): array
    {
        $out = [];
        foreach ($this->renderer->placeholders() as $namespace => $definitions) {
            foreach ($definitions as $definition) {
                $out[$namespace][] = $namespace . '.' . $definition->name;
            }
        }

        return $out;
    }

    /**
     * The call's context once `edit` is held tenant-wide.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    private function context(): CrmContext
    {
        return $this->actors->access()->context(CrmPermission::Edit);
    }

    /**
     * A row the person may see.
     */
    private function stored(CrmContext $context, string $id): StoredTemplate
    {
        if (!TemplateRef::isValid($id)) {
            throw new NotAvailableException();
        }

        return $this->call(fn (): StoredTemplate => $this->library->get($this->actors->actor($context), $id), $id); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * Deletes a row (true once done).
     */
    private function deleted(CrmContext $context, string $id, int $expectedVersion): bool
    {
        $this->library->delete($this->actors->actor($context), $id, $expectedVersion);

        return true;
    }

    /**
     * Writes a template's classification (snippets have none) and the audit entry, after MailKit stored the row.
     */
    private function record(CrmContext $context, StoredTemplate $stored, ?string $purpose, string $action): void
    {
        try {
            $this->support->write(function (Effects $effects) use ($context, $stored, $purpose, $action): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                if ($stored->kind === TemplateKind::Template) {
                    $this->store->classify($context->tenant, $stored->id, $purpose, $context->now);
                }
                $this->support->audit($effects, $context, $action, null, null, ['template' => $stored->id, 'kind' => $stored->kind->value, 'shared' => $stored->scope === TemplateScope::Shared, 'purpose' => $purpose]);
            });
        } catch (DuplicateRowException $e) {
            // Another person classified the same template first (its first classification): show the current state.
            throw new ConflictException(ConflictException::STALE, $stored->id, $e);
        }
    }

    /**
     * The checked consent purpose of a template input (null: a service message, and always for a snippet).
     */
    private function purpose(EmailTemplateInput $input): ?string
    {
        if ($input->kind === EmailTemplateKind::Snippet || $input->purpose === null) {
            return null;
        }
        if (!in_array($input->purpose, $this->purposes, true)) {
            throw InvalidInputException::field('purpose', 'error.consent_purpose');
        }

        return $input->purpose;
    }

    /**
     * MailKit's draft of an input: the text body only (an HTML body is replaced), no subject for a snippet.
     */
    private function draft(EmailTemplateInput $input): TemplateDraft
    {
        return new TemplateDraft(
            TemplateKind::from($input->kind->value),
            $input->shared ? TemplateScope::Shared : TemplateScope::Personal,
            trim($input->key),
            $input->name,
            trim($input->locale),
            $input->kind === EmailTemplateKind::Snippet ? '' : trim($input->subject),
            '',
            $input->text,
        );
    }

    /**
     * The CRM view of a row.
     *
     * @param array<string, string|null> $classes
     */
    private function view(TemplateSummary $summary, string $text, bool $html, array $classes, bool $manage): EmailTemplateView
    {
        $shared = $summary->scope === TemplateScope::Shared;

        return new EmailTemplateView(
            $summary->id,
            EmailTemplateKind::from($summary->kind->value),
            $shared,
            $summary->key,
            $summary->name,
            $summary->locale,
            $summary->subject,
            $text,
            $html,
            $summary->version,
            array_key_exists($summary->id, $classes),
            $classes[$summary->id] ?? null,
            !$shared || $manage,
            $summary->updatedAt,
        );
    }

    /**
     * Runs a MailKit call and turns its exceptions into the CRM's.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function call(callable $work, ?string $id = null): mixed
    {
        try {
            return $work();
        } catch (TemplateNotFound) {
            throw new NotAvailableException();
        } catch (TemplateAccessDenied) {
            throw new AccessDeniedException();
        } catch (TemplateVersionConflict $e) {
            throw new ConflictException(ConflictException::STALE, $id, $e);
        } catch (TemplateKeyTaken) {
            throw InvalidInputException::field('key', 'template_error.key_taken');
        } catch (InvalidTemplate $e) {
            throw InvalidInputException::field($this->field($e->field), 'template_error.' . (in_array($e->field, self::FIELDS, true) ? $e->field : 'body'));
        }
    }

    /**
     * The form field of a MailKit input field.
     */
    private function field(string $field): string
    {
        return match ($field) {
            'key', 'locale', 'name', 'subject' => $field,
            'scope'                            => 'shared',
            default                            => 'text',
        };
    }
}
