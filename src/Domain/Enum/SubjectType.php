<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * The kind of CRM record a note, tag, link or timeline entry belongs to.
 */
enum SubjectType: string
{
    case Company = 'company';
    case Contact = 'contact';

    /** Route requirement of the URL segment. */
    public const SEGMENT_REQUIREMENT = 'companies|contacts';

    /**
     * The URL segment (`companies`, `contacts`).
     */
    public function segment(): string
    {
        return match ($this) {
            self::Company => 'companies',
            self::Contact => 'contacts',
        };
    }

    /**
     * The type of a URL segment, or null.
     */
    public static function fromSegment(string $segment): ?self
    {
        return match ($segment) {
            'companies' => self::Company,
            'contacts'  => self::Contact,
            default     => null,
        };
    }

    /**
     * The plan-limit resource of the type.
     */
    public function resource(): CrmResource
    {
        return match ($this) {
            self::Company => CrmResource::Companies,
            self::Contact => CrmResource::Contacts,
        };
    }
}
