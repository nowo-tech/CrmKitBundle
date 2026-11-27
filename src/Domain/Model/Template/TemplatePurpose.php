<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Template;

use DateTimeImmutable;

/**
 * How the CRM classifies a stored e-mail template (0.13.0, `templates.enabled`): one row per MailKit template id and
 * tenant, the consent purpose a recipient must have granted (a commercial template), or none (a service message). A
 * template without a row is unclassified: it cannot be sent until a manager classifies it (deny by default). The row is
 * deleted with the template (MailKit's `TemplateDeletedEvent`). Audit ZDI-L1: the row names the MailKit template version
 * it was written for; a classification of another version counts as none (fail closed), so a race between two saves or a
 * crash between MailKit's commit and the CRM's never pairs a text with the purpose decided for another text.
 */
class TemplatePurpose
{
    /**
     * @param string $id the MailKit template id
     * @param string|null $purpose a `gdpr.purposes` key, or null for a service message
     * @param int $version the MailKit template version it classifies
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private ?string $purpose,
        private int $version,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /** The MailKit template id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** The consent purpose, or null for a service message. */
    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    /** The MailKit template version it classifies. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** When it was last set. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Sets the purpose of a version. */
    public function classify(?string $purpose, int $version, DateTimeImmutable $now): void
    {
        $this->purpose   = $purpose;
        $this->version   = $version;
        $this->updatedAt = $now;
    }
}
