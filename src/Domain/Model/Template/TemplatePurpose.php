<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Template;

use DateTimeImmutable;

/**
 * How the CRM classifies a stored e-mail template (0.13.0, `templates.enabled`): one row per MailKit template id and
 * tenant, the consent purpose a recipient must have granted (a commercial template), or none (a service message). A
 * template without a row is unclassified: it cannot be sent until a manager classifies it (deny by default). The row is
 * deleted with the template (MailKit's `TemplateDeletedEvent`).
 */
class TemplatePurpose
{
    /**
     * @param string $id the MailKit template id
     * @param string|null $purpose a `gdpr.purposes` key, or null for a service message
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private ?string $purpose,
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

    /** When it was last set. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Sets the purpose. */
    public function classify(?string $purpose, DateTimeImmutable $now): void
    {
        $this->purpose   = $purpose;
        $this->updatedAt = $now;
    }
}
