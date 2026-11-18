<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use LogicException;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

use function is_string;
use function mb_strtolower;
use function parse_url;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function strtoupper;
use function substr;

use const PHP_URL_HOST;

/**
 * A company (contract section 6.2).
 */
class Company extends CrmRecord
{
    private string $name = '';

    private string $nameKey = '';

    private ?string $legalName = null;

    private ?string $taxId = null;

    private ?string $taxIdKey = null;

    private ?string $website = null;

    private ?string $domainKey = null;

    private ?string $industry = null;

    private ?string $description = null;

    /**
     * Companies.
     */
    public static function type(): SubjectType
    {
        return SubjectType::Company;
    }

    /**
     * The name.
     */
    public function label(): string
    {
        return $this->name;
    }

    /**
     * The lowercase name (duplicate hints).
     */
    public function getNameKey(): string
    {
        return $this->nameKey;
    }

    /**
     * The tax id without spaces, dots, dashes or slashes, uppercase.
     */
    public function getTaxIdKey(): ?string
    {
        return $this->taxIdKey;
    }

    /**
     * The website's host without `www.`.
     */
    public function getDomainKey(): ?string
    {
        return $this->domainKey;
    }

    protected function specific(string $field): ?string
    {
        return match ($field) {
            'name'        => $this->name,
            'legal_name'  => $this->legalName,
            'tax_id'      => $this->taxId,
            'website'     => $this->website,
            'industry'    => $this->industry,
            'description' => $this->description,
            default       => throw new LogicException(sprintf('Unknown company field "%s".', $field)),
        };
    }

    protected function assignSpecific(string $field, ?string $value): void
    {
        match ($field) {
            'name'       => $this->name        = (string) $value,
            'legal_name' => $this->legalName   = $value,
            'tax_id'     => $this->taxId       = $value,
            'website'    => $this->website     = $value,
            'industry'   => $this->industry    = $value,
            default      => $this->description = $value,
        };
    }

    protected function refreshKeys(): void
    {
        $this->nameKey   = mb_strtolower($this->name, 'UTF-8');
        $this->taxIdKey  = $this->taxId === null ? null : strtoupper((string) preg_replace('/[\s.\-\/]+/u', '', $this->taxId));
        $host            = $this->website === null ? null : parse_url($this->website, PHP_URL_HOST);
        $host            = is_string($host) ? mb_strtolower($host, 'UTF-8') : null;
        $this->domainKey = $host !== null && str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
