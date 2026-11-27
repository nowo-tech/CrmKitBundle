<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;

/**
 * A stored e-mail template or snippet as the CRM shows it (0.13.0): MailKit's row (only rows the person may see) with
 * the CRM's consent classification. `text` is filled by a single read only (empty in lists). Name, subject and text are
 * untrusted (shown escaped).
 */
final readonly class EmailTemplateView
{
    /**
     * @param bool $shared a template of the organisation (else the person's own)
     * @param bool $hasHtml the row has an HTML body (a save from the CRM's form replaces it by the text)
     * @param bool $classified the CRM has a classification row for it (snippets need none)
     * @param string|null $purpose the consent purpose of a commercial template, null for a service message
     * @param bool $manageable the person may change and delete it
     */
    public function __construct(
        public string $id,
        public EmailTemplateKind $kind,
        public bool $shared,
        public string $key,
        public string $name,
        public string $locale,
        public string $subject,
        public string $text,
        public bool $hasHtml,
        public int $version,
        public bool $classified,
        public ?string $purpose,
        public bool $manageable,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Whether a message from it may be sent without a classification decision (a snippet, or a classified template).
     */
    public function sendable(): bool
    {
        return $this->kind === EmailTemplateKind::Snippet || $this->classified;
    }
}
