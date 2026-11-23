<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotProperty;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotRecord;
use Nowo\CrmKitBundle\Domain\Value\CompanyDomain;

use function array_key_exists;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strtoupper;
use function substr;
use function trim;

/**
 * The HubSpot ⇒ CRM field rules (0.10.0, design section 11), pure: the default targets, the targets a property may
 * map to, and the values of one record. An empty HubSpot value maps to nothing, so it never clears a CRM value
 * (Q-H7). Custom HubSpot properties default to "skip".
 *
 * @internal
 */
final class HubSpotMapper
{
    /** HubSpot's owner property. */
    public const OWNER_PROPERTY = 'hubspot_owner_id';

    /** Default targets of the company properties. */
    public const COMPANY_DEFAULTS = [
        'name'             => 'name',
        'website'          => 'website',
        'domain'           => 'domain',
        'phone'            => 'phone',
        'address'          => 'street',
        'city'             => 'city',
        'state'            => 'region',
        'zip'              => 'postal_code',
        'country'          => 'country',
        'industry'         => 'industry',
        'description'      => 'description',
        'hubspot_owner_id' => 'owner',
    ];

    /** Default targets of the contact properties. */
    public const CONTACT_DEFAULTS = [
        'firstname'        => 'first_name',
        'lastname'         => 'last_name',
        'email'            => 'email',
        'phone'            => 'phone',
        'mobilephone'      => 'mobile',
        'jobtitle'         => 'job_title',
        'address'          => 'street',
        'city'             => 'city',
        'state'            => 'region',
        'zip'              => 'postal_code',
        'country'          => 'country',
        'hubspot_owner_id' => 'owner',
    ];

    /** Row note of a country that is not an ISO 3166 alpha-2 code (the field is left as it is). */
    public const COUNTRY_UNKNOWN = 'import.hubspot.country_unknown';

    /**
     * The default property => target map of an object, for the properties the account has.
     *
     * @param list<HubSpotProperty> $properties
     *
     * @return array<string, string>
     */
    public static function defaults(HubSpotObject $object, array $properties): array
    {
        $defaults = $object === HubSpotObject::Company ? self::COMPANY_DEFAULTS : self::CONTACT_DEFAULTS;
        $mapping  = [];
        foreach ($properties as $property) {
            $target = $defaults[$property->name->value] ?? null;
            if (is_string($target)) {
                $mapping[$property->name->value] = $target;
            }
        }

        return $mapping;
    }

    /**
     * The targets a property of the object may map to: skip, the core fields, the owner, the company domain and the
     * active custom fields.
     *
     * @param list<string> $customKeys
     *
     * @return list<string>
     */
    public static function targets(HubSpotObject $object, array $customKeys): array
    {
        $targets = [''];
        foreach (RecordFields::names($object->subjectType()) as $field) {
            $targets[] = $field;
        }
        if ($object === HubSpotObject::Company) {
            $targets[] = 'domain';
        }
        $targets[] = 'owner';
        foreach ($customKeys as $key) {
            $targets[] = 'custom.' . $key;
        }

        return $targets;
    }

    /**
     * The CRM values of one HubSpot record: core values and custom values (non-empty only), the HubSpot owner id
     * (null when not mapped or empty) and the row note (null, or {@see self::COUNTRY_UNKNOWN}).
     *
     * @param array<array-key, string> $mapping property => target
     *
     * @return array{values: array<string, string>, custom: array<string, string>, owner: string|null, note: string|null}
     */
    public static function map(HubSpotObject $object, HubSpotRecord $record, array $mapping): array
    {
        $fields = RecordFields::of($object->subjectType());
        $values = [];
        $custom = [];
        $owner  = null;
        $note   = null;
        $domain = null;
        foreach ($mapping as $property => $target) {
            $value = trim($record->value((string) $property) ?? '');
            if ($target === '' || $value === '') {
                continue;
            }
            if ($target === 'owner') {
                $owner = $value;
            } elseif ($target === 'domain') {
                $domain = $value;
            } elseif (str_starts_with($target, 'custom.')) {
                $custom[substr($target, 7)] = $value;
            } elseif ($target === 'country') {
                if (preg_match('/\A[A-Za-z]{2}\z/D', $value) === 1) {
                    $values['country'] = strtoupper($value);
                } else {
                    $note = self::COUNTRY_UNKNOWN;
                }
            } elseif (array_key_exists($target, $fields)) {
                $values[$target] = $value;
            }
        }
        if (!array_key_exists('website', $values) && $domain !== null) {
            // A bare domain becomes the website only when it is a usable host.
            $key = CompanyDomain::key($domain);
            if ($key !== null && preg_match('/\A[a-z0-9.-]+\.[a-z]{2,}\z/D', $key) === 1) {
                $values['website'] = 'https://' . $key;
            }
        }

        return ['values' => $values, 'custom' => $custom, 'owner' => $owner, 'note' => $note];
    }
}
