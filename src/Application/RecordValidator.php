<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;

use function array_key_exists;
use function mb_strlen;
use function preg_match;
use function strtoupper;
use function trim;

/**
 * Cleans and validates record input (contract sections 6.2 and 6.4): trims, turns empty values into null, checks
 * lengths and kinds, the required fields and the custom values. Collects every error. Stateless.
 *
 * @internal
 */
final class RecordValidator
{
    /**
     * Clean core values (only the keys given).
     *
     * @param array<string, string|null> $raw
     *
     * @throws InvalidInputException
     *
     * @return array<string, string|null>
     */
    public static function values(SubjectType $type, array $raw, bool $creating): array
    {
        $fields = RecordFields::of($type);
        $clean  = [];
        $errors = [];
        foreach ($raw as $field => $value) {
            if (!array_key_exists($field, $fields)) {
                $errors[$field] = 'error.unknown_field';

                continue;
            }
            [$max, $kind, $required] = $fields[$field];
            $value                   = $value === null ? null : trim($value);
            $value                   = $value === '' ? null : $value;
            if ($value === null) {
                if ($required) {
                    $errors[$field] = 'error.required';
                }
                $clean[$field] = null;

                continue;
            }
            if ($kind === 'country') {
                $value = strtoupper($value);
            }
            $error = self::check($value, $max, $kind);
            if ($error !== null) {
                $errors[$field] = $error;

                continue;
            }
            $clean[$field] = $value;
        }
        if ($creating) {
            foreach ($fields as $field => [, , $required]) {
                if ($required && !array_key_exists($field, $raw)) {
                    $errors[$field] = 'error.required';
                }
            }
        }
        if ($errors !== []) {
            throw new InvalidInputException($errors);
        }

        return $clean;
    }

    /**
     * A contact needs a first or a last name.
     *
     * @param array<string, string|null> $values the record's values after the change
     *
     * @throws InvalidInputException
     */
    public static function contactName(array $values): void
    {
        if (($values['first_name'] ?? null) === null && ($values['last_name'] ?? null) === null) {
            throw InvalidInputException::field('last_name', 'error.contact_name');
        }
    }

    /**
     * Clean custom values against the active definitions (unknown keys refused, archived values kept).
     *
     * @param array<string, mixed> $raw
     * @param list<FieldDefinition> $definitions
     * @param array<string, string> $current the record's current values
     *
     * @throws InvalidInputException
     *
     * @return array<string, string>
     */
    public static function custom(array $raw, array $definitions, array $current): array
    {
        $clean  = [];
        $errors = [];
        $known  = [];
        foreach ($definitions as $definition) {
            $key         = $definition->getFieldKey();
            $known[$key] = true;
            if ($definition->isArchived()) {
                if (array_key_exists($key, $current)) {
                    $clean[$key] = $current[$key];
                }

                continue;
            }
            try {
                $value = $definition->normalize($raw[$key] ?? null);
            } catch (InvalidArgumentException $e) {
                $errors['custom.' . $key] = $e->getMessage();

                continue;
            }
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }
        foreach ($raw as $key => $unused) {
            if (!array_key_exists($key, $known)) {
                $errors['custom.' . $key] = 'error.unknown_field';
            }
        }
        if ($errors !== []) {
            throw new InvalidInputException($errors);
        }

        return $clean;
    }

    /**
     * The error key of a non-empty value, or null.
     */
    public static function check(string $value, int $max, string $kind): ?string
    {
        if (preg_match('//u', $value) !== 1) {
            return 'error.invalid';
        }
        if (mb_strlen($value) > $max) {
            return 'error.too_long';
        }
        $controls = $kind === 'textarea' ? '/[^\P{Cc}\n\r\t]|\p{Cf}/u' : '/[\p{Cc}\p{Cf}]/u';
        if (preg_match($controls, $value) === 1) {
            return 'error.invalid';
        }

        return match ($kind) {
            'email'   => self::email($value) ? null : 'error.email',
            'url'     => preg_match('#\Ahttps?://[^\s/?\#]+[^\s]*\z#iD', $value) === 1 ? null : 'error.url',
            'phone'   => preg_match('#\A\+?[0-9 ()./-]{3,40}\z#D', $value) === 1 ? null : 'error.phone',
            'country' => preg_match('/\A[A-Z]{2}\z/D', $value) === 1 ? null : 'error.country',
            default   => null,
        };
    }

    private static function email(string $value): bool
    {
        try {
            new EmailAddress($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
