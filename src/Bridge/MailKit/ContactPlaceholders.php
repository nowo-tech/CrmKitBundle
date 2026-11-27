<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;

/**
 * `{{ contact.* }}` (0.13.0): the core fields of the contact named by the render's `contact` subject, read through the
 * CRM's reader (tenant, `view` and the host's record checks), only while it is active. Custom fields are not offered.
 */
final class ContactPlaceholders extends CrmPlaceholders
{
    /** Core fields offered (name ⇒ label key). */
    public const NAMES = [
        'first_name'  => 'field.first_name',
        'last_name'   => 'field.last_name',
        'full_name'   => 'template.placeholder.full_name',
        'email'       => 'field.email',
        'phone'       => 'field.phone',
        'mobile'      => 'field.mobile',
        'job_title'   => 'field.job_title',
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
     * `contact`.
     */
    public static function placeholderNamespace(): string
    {
        return 'contact';
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
        return RecordPlaceholderValues::of($this->reader, SubjectType::Contact, self::subjectId($placeholders, 'contact'));
    }
}
