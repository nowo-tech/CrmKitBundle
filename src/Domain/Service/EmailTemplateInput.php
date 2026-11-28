<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;

/**
 * A template or snippet as a person writes it in the CRM (0.13.0): the CRM's form edits the text body only (a save
 * replaces an HTML body written elsewhere). Errors come back per field: `key`, `locale`, `name`, `subject`, `text`,
 * `purpose`, `shared`.
 */
final readonly class EmailTemplateInput
{
    /**
     * @param bool $shared a template of the organisation (needs `manage`); a row's scope never changes
     * @param string $subject the subject of a template ('' for a snippet)
     * @param string|null $purpose a template's consent purpose (`gdpr.purposes`), null for a service message; ignored for snippets
     */
    public function __construct(
        public EmailTemplateKind $kind,
        public bool $shared,
        public string $key,
        public string $name,
        public string $locale,
        public string $subject,
        public string $text,
        public ?string $purpose = null,
    ) {
    }
}
