<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function mb_strtolower;
use function preg_replace;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Column mapping of an import (contract section 9): targets are the core fields, `custom.<key>`, `tags` (comma
 * separated existing tag names) and, for contacts, `company` (an existing active company of that name); '' skips a
 * column. Stateless.
 *
 * @internal
 */
final class ImportMapping
{
    /** Column names (normalised) that map to a field besides the field names themselves. */
    private const ALIASES = [
        'e_mail'       => 'email',
        'mail'         => 'email',
        'firstname'    => 'first_name',
        'given_name'   => 'first_name',
        'lastname'     => 'last_name',
        'surname'      => 'last_name',
        'family_name'  => 'last_name',
        'telephone'    => 'phone',
        'tel'          => 'phone',
        'cell'         => 'mobile',
        'title'        => 'job_title',
        'position'     => 'job_title',
        'zip'          => 'postal_code',
        'postcode'     => 'postal_code',
        'web'          => 'website',
        'url'          => 'website',
        'vat'          => 'tax_id',
        'vat_number'   => 'tax_id',
        'organization' => 'company',
        'organisation' => 'company',
        'company_name' => 'company',
        'address'      => 'street',
        'state'        => 'region',
        'province'     => 'region',
    ];

    /**
     * Every target of a type.
     *
     * @param list<FieldDefinition> $fields active custom fields
     *
     * @return list<string>
     */
    public static function targets(SubjectType $type, array $fields): array
    {
        $targets = RecordFields::names($type);
        if ($type === SubjectType::Contact) {
            $targets[] = 'company';
        }
        $targets[] = 'tags';
        foreach ($fields as $field) {
            $targets[] = 'custom.' . $field->getFieldKey();
        }

        return $targets;
    }

    /**
     * The detected mapping of the columns.
     *
     * @param list<string> $columns
     * @param list<string> $targets
     *
     * @return array<string, string>
     */
    public static function detect(array $columns, array $targets): array
    {
        $mapping = [];
        $used    = [];
        foreach ($columns as $column) {
            $key    = (string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($column), 'UTF-8'));
            $key    = trim($key, '_');
            $target = self::ALIASES[$key] ?? $key;
            if (in_array($target, $targets, true) && !isset($used[$target])) {
                $mapping[$column] = $target;
                $used[$target]    = true;
            } elseif (in_array('custom.' . $key, $targets, true) && !isset($used['custom.' . $key])) {
                $mapping[$column]       = 'custom.' . $key;
                $used['custom.' . $key] = true;
            } else {
                $mapping[$column] = '';
            }
        }

        return $mapping;
    }

    /**
     * Whether a chosen mapping is usable: known columns, known targets, each target once.
     *
     * @param array<string, string> $mapping
     * @param list<string> $columns
     * @param list<string> $targets
     */
    public static function valid(array $mapping, array $columns, array $targets): bool
    {
        $used = [];
        foreach ($mapping as $column => $target) {
            if (!in_array((string) $column, $columns, true)) {
                return false;
            }
            if ($target === '') {
                continue;
            }
            if (!in_array($target, $targets, true) || isset($used[$target])) {
                return false;
            }
            $used[$target] = true;
        }

        return $used !== [];
    }

    /**
     * The record input of one row, plus the tag names and the company name it names.
     *
     * @param array<string, string> $row
     * @param array<string, string> $mapping
     *
     * @return array{RecordInput, list<string>, string|null}
     */
    public static function input(SubjectType $type, array $row, array $mapping): array
    {
        $values  = [];
        $custom  = [];
        $tags    = [];
        $company = null;
        $fields  = RecordFields::of($type);
        foreach ($mapping as $column => $target) {
            $cell = $row[(string) $column] ?? '';
            if ($target === '') {
                continue;
            }
            if ($target === 'tags') {
                $tags = array_values(array_filter(array_map(trim(...), explode(',', $cell)), static fn (string $t): bool => $t !== ''));
            } elseif ($target === 'company') {
                $company = trim($cell) === '' ? null : trim($cell);
            } elseif (str_starts_with($target, 'custom.')) {
                $custom[substr($target, 7)] = $cell;
            } elseif (array_key_exists($target, $fields)) {
                $values[$target] = $cell;
            }
        }

        return [new RecordInput($type, $values, null, $custom, null, false), $tags, $company];
    }
}
