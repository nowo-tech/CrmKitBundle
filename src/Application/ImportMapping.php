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
use function preg_split;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Column mapping of an import (contract section 9): targets are the core fields, `custom.<key>`, `tags` (comma
 * separated existing tag names), `roles` (role keys separated by `,` or `;`) and, for contacts, `company` (an existing
 * active company of that name) with `purposes` (purpose keys of that affiliation) and `primary_contact` (yes / no:
 * the company's primary contact); '' skips a column. Stateless.
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
        'role'         => 'roles',
        'party_roles'  => 'roles',
        'purpose'      => 'purposes',
        'primary'      => 'primary_contact',
    ];

    /** Cells read as "yes" in a `primary_contact` column (trimmed, lowercase). */
    private const YES = ['1', 'true', 'yes', 'y', 'x'];

    /** Cells read as "no" in a `primary_contact` column (trimmed, lowercase). */
    private const NO = ['', '0', 'false', 'no', 'n'];

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
            $targets[] = 'purposes';
            $targets[] = 'primary_contact';
        }
        $targets[] = 'tags';
        $targets[] = 'roles';
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
     * The record input of one row (with the row's roles, `null` when none are given), plus the tag names, the company
     * name it names and that affiliation's purposes and primary-contact cell (`null`: no such column or an empty one).
     *
     * @param array<string, string> $row
     * @param array<string, string> $mapping
     *
     * @return array{RecordInput, list<string>, string|null, list<string>, string|null}
     */
    public static function input(SubjectType $type, array $row, array $mapping): array
    {
        $values   = [];
        $custom   = [];
        $tags     = [];
        $roles    = [];
        $purposes = [];
        $primary  = null;
        $company  = null;
        $fields   = RecordFields::of($type);
        foreach ($mapping as $column => $target) {
            $cell = $row[(string) $column] ?? '';
            if ($target === '') {
                continue;
            }
            if ($target === 'tags') {
                $tags = array_values(array_filter(array_map(trim(...), explode(',', $cell)), static fn (string $t): bool => $t !== ''));
            } elseif ($target === 'roles') {
                $roles = self::keys($cell);
            } elseif ($target === 'purposes') {
                $purposes = self::keys($cell);
            } elseif ($target === 'primary_contact') {
                $primary = trim($cell) === '' ? null : trim($cell);
            } elseif ($target === 'company') {
                $company = trim($cell) === '' ? null : trim($cell);
            } elseif (str_starts_with($target, 'custom.')) {
                $custom[substr($target, 7)] = $cell;
            } elseif (array_key_exists($target, $fields)) {
                $values[$target] = $cell;
            }
        }

        return [new RecordInput($type, $values, null, $custom, null, false, $roles === [] ? null : $roles), $tags, $company, $purposes, $primary];
    }

    /**
     * A yes / no cell of a `primary_contact` column: true, false, or null when it is neither.
     */
    public static function yes(?string $cell): ?bool
    {
        $cell = mb_strtolower(trim($cell ?? ''), 'UTF-8');

        return in_array($cell, self::YES, true) ? true : (in_array($cell, self::NO, true) ? false : null);
    }

    /**
     * The keys of a cell: separated by `,` or `;`, trimmed (never case folded: keys are compared exactly).
     *
     * @return list<string>
     */
    private static function keys(string $cell): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[,;]/', $cell) ?: []), static fn (string $k): bool => $k !== ''));
    }
}
