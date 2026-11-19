<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * The core fields of companies and contacts (contract section 6.2): name => [max length, kind, required]. Forms,
 * validation, import mapping, CSV export and the merge preview are all driven by this list.
 *
 * Kinds: `text`, `textarea`, `email`, `url`, `phone`, `country` (ISO 3166-1 alpha-2).
 */
final class RecordFields
{
    /** Fields of a company. */
    public const COMPANY = [
        'name'        => [200, 'text', true],
        'legal_name'  => [200, 'text', false],
        'tax_id'      => [64, 'text', false],
        'website'     => [255, 'url', false],
        'email'       => [254, 'email', false],
        'phone'       => [40, 'phone', false],
        'industry'    => [80, 'text', false],
        'street'      => [255, 'text', false],
        'postal_code' => [20, 'text', false],
        'city'        => [120, 'text', false],
        'region'      => [120, 'text', false],
        'country'     => [2, 'country', false],
        'description' => [5000, 'textarea', false],
    ];

    /** Fields of a contact (a first or a last name is required). */
    public const CONTACT = [
        'first_name'  => [120, 'text', false],
        'last_name'   => [120, 'text', false],
        'email'       => [254, 'email', false],
        'phone'       => [40, 'phone', false],
        'mobile'      => [40, 'phone', false],
        'job_title'   => [120, 'text', false],
        'street'      => [255, 'text', false],
        'postal_code' => [20, 'text', false],
        'city'        => [120, 'text', false],
        'region'      => [120, 'text', false],
        'country'     => [2, 'country', false],
    ];

    /**
     * The fields of a type.
     *
     * @return array<string, array{int, string, bool}>
     */
    public static function of(SubjectType $type): array
    {
        return $type === SubjectType::Company ? self::COMPANY : self::CONTACT;
    }

    /**
     * The field names of a type.
     *
     * @return list<string>
     */
    public static function names(SubjectType $type): array
    {
        return array_keys(self::of($type));
    }
}
