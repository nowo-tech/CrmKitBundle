<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * A HubSpot CRM object kind the import reads (0.10.0, slice H1: companies and contacts; deals come with H2). The value
 * is HubSpot's path segment (`/crm/v3/objects/{value}`).
 */
enum HubSpotObject: string
{
    case Company = 'companies';
    case Contact = 'contacts';

    /**
     * The CRM record type it becomes.
     */
    public function subjectType(): SubjectType
    {
        return match ($this) {
            self::Company => SubjectType::Company,
            self::Contact => SubjectType::Contact,
        };
    }

    /**
     * The `object_type` / `target_type` key of its external references (`company`, `contact`).
     */
    public function refType(): string
    {
        return $this->subjectType()->value;
    }

    /**
     * The read scopes a token needs to import it (the object and its property schema).
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return ['crm.objects.' . $this->value . '.read', 'crm.schemas.' . $this->value . '.read'];
    }

    /**
     * The object of a record type, or null (deals: slice H2).
     */
    public static function ofSubject(SubjectType $type): ?self
    {
        return match ($type) {
            SubjectType::Company => self::Company,
            SubjectType::Contact => self::Contact,
            SubjectType::Deal    => null,
        };
    }
}
