<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use LogicException;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

use function mb_strtolower;
use function sprintf;
use function trim;

/**
 * A contact (contract section 6.2): a person, related to companies through affiliations. The e-mail key is unique per
 * tenant.
 */
class Contact extends CrmRecord
{
    private ?string $firstName = null;

    private ?string $lastName = null;

    private string $displayName = '';

    private string $sortKey = '';

    private ?string $emailKey = null;

    private ?string $mobile = null;

    private ?string $jobTitle = null;

    private ?DateTimeImmutable $erasedAt = null;

    /**
     * Contacts.
     */
    public static function type(): SubjectType
    {
        return SubjectType::Contact;
    }

    /**
     * "First Last".
     */
    public function label(): string
    {
        return $this->displayName;
    }

    /**
     * The lowercase e-mail (unique per tenant), or null.
     */
    public function getEmailKey(): ?string
    {
        return $this->emailKey;
    }

    /**
     * The lowercase "last first" (sort order, duplicate hints).
     */
    public function getSortKey(): string
    {
        return $this->sortKey;
    }

    /**
     * When it was erased (GDPR).
     */
    public function getErasedAt(): ?DateTimeImmutable
    {
        return $this->erasedAt;
    }

    /**
     * Erases the personal data (contract section 16.3): the name becomes the placeholder, every other personal value
     * is removed.
     */
    public function erase(string $placeholder, DateTimeImmutable $now, ?string $actor): void
    {
        $this->blank();
        $this->apply(['last_name' => $placeholder]);
        $this->status   = RecordStatus::Erased;
        $this->erasedAt = $now;
        $this->releaseKeys();
        $this->touch($now, $actor);
    }

    protected function releaseKeys(): void
    {
        $this->emailKey = null;
    }

    protected function specific(string $field): ?string
    {
        return match ($field) {
            'first_name' => $this->firstName,
            'last_name'  => $this->lastName,
            'mobile'     => $this->mobile,
            'job_title'  => $this->jobTitle,
            default      => throw new LogicException(sprintf('Unknown contact field "%s".', $field)),
        };
    }

    protected function assignSpecific(string $field, ?string $value): void
    {
        match ($field) {
            'first_name' => $this->firstName = $value,
            'last_name'  => $this->lastName  = $value,
            'mobile'     => $this->mobile    = $value,
            default      => $this->jobTitle  = $value,
        };
    }

    protected function refreshKeys(): void
    {
        $this->displayName = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
        $this->sortKey     = mb_strtolower(trim(($this->lastName ?? '') . ' ' . ($this->firstName ?? '')), 'UTF-8');
        $this->emailKey    = $this->status === RecordStatus::Merged || $this->status === RecordStatus::Erased || $this->email === null ? null : mb_strtolower($this->email, 'UTF-8');
    }
}
