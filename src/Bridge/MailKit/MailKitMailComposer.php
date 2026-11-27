<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\Templates\Port\TemplateMailStoreInterface;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\CrmMailSent;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Exception\RateLimitedException;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmMailSenderInterface;
use Nowo\CrmKitBundle\Domain\Read\MailOutcome;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MailComposerInterface;
use Nowo\CrmKitBundle\Domain\Service\MailDraft;
use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\EmailExcerpt;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TemplateRef;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_key_exists;
use function is_string;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * {@see MailComposerInterface} (0.13.0): the CRM's checks in one write unit on the locked contact (`edit`, active, an
 * address, the template's consent purpose granted — read under the same lock a consent change takes —, the
 * per-person rate limit and the one-time send key), then the send through {@see CrmMailSenderInterface} outside any
 * transaction (a deadlock replay must never send twice), then the `email` activity on the contact (its own unit).
 * A failed send releases the key and logs nothing; a failed log after a send is logged as an error and reported in the
 * outcome (the mail went out). Stateless.
 */
final readonly class MailKitMailComposer implements MailComposerInterface
{
    /**
     * @param int $rateLimit sends per person in `$rateInterval` seconds
     */
    public function __construct(
        private CrmTemplateActors $actors,
        private TemplateLibraryInterface $library,
        private TemplateMailStoreInterface $store,
        private WriteSupport $support,
        private EngagementServiceInterface $engagements,
        private CrmMailSenderInterface $sender,
        private CrmActorDirectoryInterface $directory,
        private int $rateLimit,
        private int $rateInterval,
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function send(MailDraft $draft): MailOutcome
    {
        $context = $this->actors->access()->context(CrmPermission::Edit);
        if (!RecordId::isValid($draft->contactId)) {
            throw new NotAvailableException();
        }
        $this->validate($draft);
        [$template, $purpose] = $this->template($context, $draft->templateId);
        /** @var array{string, string} $recipient */
        $recipient = $this->support->write(function (Effects $effects) use ($context, $draft, $template, $purpose): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $contact = $this->support->load($context, SubjectType::Contact, $draft->contactId, CrmPermission::Edit, lock: true);
            if ($purpose !== null && !$this->consents($context, $draft->contactId, $purpose)) {
                throw InvalidInputException::field('template', 'mail_error.no_consent', ['%purpose%' => $purpose]);
            }
            $address = $contact->value('email');
            if ($address === null) {
                throw InvalidInputException::field('to', 'mail_error.no_address');
            }
            if (!$this->store->takeSend($context->tenant, $context->actor->value, $context->now, $this->rateLimit, $this->rateInterval)) {
                throw new RateLimitedException($this->rateInterval);
            }
            if (!$this->store->claim($context->tenant, $draft->sendKey, $context->now)) {
                throw new ConflictException(ConflictException::MAIL_SENT, $draft->contactId);
            }
            $this->support->audit($effects, $context, 'crm.mail.claimed', SubjectType::Contact, $draft->contactId, ['template' => $template?->id, 'purpose' => $purpose]);

            return [$address, $contact->label()];
        });
        $this->deliver($context, $draft, $recipient, $template);
        $engagement = $this->log($context, $draft);
        $this->events?->dispatch(new CrmMailSent($context->tenant, $context->actor, $context->now, $draft->contactId, $engagement, $template?->id, $purpose));

        return new MailOutcome($engagement);
    }

    /**
     * Checks the send key, the subject and the text.
     */
    private function validate(MailDraft $draft): void
    {
        if (!MailDraft::isKey($draft->sendKey)) {
            throw InvalidInputException::field('_', 'error.csrf');
        }
        $errors = [];
        if (!CrmOutgoingMail::isLine($draft->subject, CrmOutgoingMail::MAX_SUBJECT, false)) {
            $errors['subject'] = 'mail_error.subject';
        }
        if (!CrmOutgoingMail::isText($draft->text)) {
            $errors['text'] = 'mail_error.text';
        }
        if ($errors !== []) {
            throw new InvalidInputException($errors);
        }
    }

    /**
     * The template of a draft the person may see and its consent purpose (null: a service message), or no template.
     *
     * @return array{StoredTemplate|null, string|null}
     */
    private function template(CrmContext $context, ?string $id): array
    {
        if ($id === null) {
            return [null, null];
        }
        try {
            if (!TemplateRef::isValid($id)) {
                throw new TemplateNotFound();
            }
            $template = $this->library->get($this->actors->actor($context), $id);
        } catch (TemplateNotFound) {
            throw InvalidInputException::field('template', 'mail_error.template');
        }
        if ($template->kind !== TemplateKind::Template) {
            throw InvalidInputException::field('template', 'mail_error.template');
        }
        $classes = $this->support->read(fn (): array => $this->store->classifications($context->tenant, [$template->id => $template->version])); // @igor-ignore - the runner calls this closure at once and keeps nothing
        if (!array_key_exists($template->id, $classes)) {
            throw InvalidInputException::field('template', 'mail_error.unclassified');
        }

        return [$template, $classes[$template->id]];
    }

    /**
     * Whether the contact's latest decision for the purpose is a grant (read under the contact's lock).
     */
    private function consents(CrmContext $context, string $contactId, string $purpose): bool
    {
        foreach ($this->support->store->consents($context->tenant, $contactId) as $consent) {
            if ($consent->getPurpose() === $purpose) {
                return $consent->isGranted();
            }
        }

        return false;
    }

    /**
     * Sends the message; a failure releases the send key and becomes an input error of the form.
     *
     * @param array{string, string} $recipient
     */
    private function deliver(CrmContext $context, MailDraft $draft, array $recipient, ?StoredTemplate $template): void
    {
        try {
            $profile = $this->directory->actor($context->tenant, $context->actor);
            $replyTo = is_string($profile?->email) ? $this->address($profile->email) : null;
            $this->sender->send(new CrmOutgoingMail($context->tenant, $context->actor, new EmailAddress($recipient[0]), $this->line($recipient[1], 200), $draft->subject, $draft->text, $replyTo, $template?->key, CrmMailLogTag::of($context->tenant)));
        } catch (Throwable $e) {
            $this->logger?->warning('nowo_crm_kit: an e-mail to a contact was not sent.', ['contact' => $draft->contactId, 'exception' => $e]);
            $this->store->release($context->tenant, $draft->sendKey);

            throw InvalidInputException::field('_', 'mail_error.not_sent');
        }
    }

    /**
     * Logs the sent message as an `email` activity on the contact; its id, or null when that failed.
     */
    private function log(CrmContext $context, MailDraft $draft): ?string
    {
        $excerpt = trim(mb_substr($this->clean($draft->text, '/[^\P{Cc}\t\n\r]|\p{Cf}/u'), 0, EmailExcerpt::MAX));
        try {
            return $this->engagements->log(SubjectType::Contact, $draft->contactId, new EngagementInput(
                ActivityKind::Email,
                $context->now,
                trim(mb_substr($this->clean($draft->subject, '/[\p{Cc}\p{Cf}]/u'), 0, 200)),
                $excerpt === '' ? null : $excerpt,
                ActivityDirection::Out,
            ));
        } catch (Throwable $e) {
            $this->logger?->error('nowo_crm_kit: an e-mail was sent to a contact but its activity was not logged.', ['contact' => $draft->contactId, 'exception' => $e]);

            return null;
        }
    }

    /**
     * An address, or null when it is not one.
     */
    private function address(string $value): ?EmailAddress
    {
        try {
            return new EmailAddress($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A value as one safe line of at most `$max` characters.
     */
    private function line(string $value, int $max): string
    {
        return trim(mb_substr($this->clean($value, CrmOutgoingMail::UNSAFE_LINE), 0, $max));
    }

    /**
     * A value without the characters of a pattern.
     */
    private function clean(string $value, string $pattern): string
    {
        return (string) preg_replace($pattern, '', $value);
    }
}
