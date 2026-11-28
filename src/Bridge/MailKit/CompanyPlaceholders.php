<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;

/**
 * `{{ company.* }}` (0.13.0): the core fields of the company named by the render's `company` subject (*Write e-mail*:
 * the contact's primary company), read through the CRM's reader (tenant, `view` and the host's record checks), only
 * while it is active. Custom fields are not offered.
 */
final class CompanyPlaceholders extends CrmPlaceholders
{
    /** Core fields offered (name ⇒ label key). */
    public const NAMES = [
        'name'        => 'field.name',
        'legal_name'  => 'field.legal_name',
        'tax_id'      => 'field.tax_id',
        'website'     => 'field.website',
        'industry'    => 'field.industry',
        'email'       => 'field.email',
        'phone'       => 'field.phone',
        'street'      => 'field.street',
        'postal_code' => 'field.postal_code',
        'city'        => 'field.city',
        'region'      => 'field.region',
        'country'     => 'field.country',
        'owner_name'  => 'template.placeholder.owner_name',
    ];

    /**
     * Wires the reader.
     */
    public function __construct(CrmTemplateActors $actors, private readonly CrmReaderInterface $reader)
    {
        parent::__construct($actors);
    }

    /**
     * `company`.
     */
    public static function placeholderNamespace(): string
    {
        return 'company';
    }

    /**
     * {@inheritDoc}
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * {@inheritDoc}
     */
    protected function values(CrmContext $context, PlaceholderContext $placeholders, array $names): array
    {
        return RecordPlaceholderValues::of($this->reader, SubjectType::Company, self::subjectId($placeholders, 'company'));
    }
}
