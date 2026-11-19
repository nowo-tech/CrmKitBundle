<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * The kind of CRM record a note, tag, link or timeline entry belongs to. **(0.3.0)** `Deal` is the subject of the
 * funnels (deal access checks, stage history, next actions, timeline); companies and contacts stay the only
 * "records" (fields, lists, merges, imports): {@see self::isRecord()}.
 */
enum SubjectType: string
{
    case Company = 'company';
    case Contact = 'contact';
    case Deal    = 'deal';

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
            self::Deal    => 'deals',
        };
    }

    /**
     * Whether the type is a company or a contact (the records of fields, lists, merges and imports).
     */
    public function isRecord(): bool
    {
        return $this !== self::Deal;
    }

    /**
     * The record type of a URL segment (`companies`, `contacts`), or null; never `Deal`.
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
            self::Deal    => CrmResource::Deals,
        };
    }
}
