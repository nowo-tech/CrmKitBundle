<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\FieldKey;

use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * A custom field of the tenant for companies or contacts (contract section 6.4). Values are stored as strings in the
 * record's `custom` JSON, normalised by {@see self::normalize()}.
 */
class FieldDefinition
{
    private string $fieldKey;

    private int $version = 1;

    private bool $archived = false;

    /**
     * @param list<string> $choices choice values (type choice only)
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        FieldKey $key,
        private string $label,
        private FieldType $type,
        private array $choices,
        private bool $required,
        private int $position,
        private DateTimeImmutable $createdAt,
    ) {
        $this->fieldKey = $key->value;
    }

    /** The row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Companies or contacts. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The key in the record's custom values. */
    public function getFieldKey(): string
    {
        return $this->fieldKey;
    }

    /** The label. */
    public function getLabel(): string
    {
        return $this->label;
    }

    /** The type. */
    public function getType(): FieldType
    {
        return $this->type;
    }

    /**
     * The choices.
     *
     * @return list<string>
     */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /** Whether a value is required. */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /** Order in forms. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Whether it is archived (hidden, values kept). */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** When it was defined. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Changes the label, choices, required flag and position (the key and type never change).
     *
     * @param list<string> $choices
     */
    public function change(string $label, array $choices, bool $required, int $position): void
    {
        $this->label    = $label;
        $this->choices  = $choices;
        $this->required = $required;
        $this->position = $position;
    }

    /** Archives or brings back the field. */
    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
    }

    /**
     * The stored form of a value, null when empty.
     *
     * @throws InvalidArgumentException when the value does not fit the type
     */
    public function normalize(mixed $raw): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            $raw = $raw->format('Y-m-d');
        }
        if (is_bool($raw)) {
            return $this->type === FieldType::Boolean ? ($raw ? '1' : '0') : throw new InvalidArgumentException('field.error.type');
        }
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            if ($this->required && $this->type !== FieldType::Boolean) {
                throw new InvalidArgumentException('field.error.required');
            }

            return $this->type === FieldType::Boolean ? '0' : null;
        }
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            throw new InvalidArgumentException('field.error.type');
        }
        $value = trim($raw);
        $ok    = match ($this->type) {
            FieldType::Text     => mb_strlen($value) <= 255 && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1,
            FieldType::Textarea => mb_strlen($value) <= 5000 && preg_match('/[^\P{Cc}\n\r\t]|\p{Cf}/u', $value) !== 1,
            FieldType::Number   => preg_match('/\A-?\d{1,20}(\.\d{1,9})?\z/D', $value) === 1,
            FieldType::Date     => preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) === 1 && $this->isDate($value),
            FieldType::Boolean  => in_array($value, ['0', '1'], true),
            FieldType::Choice   => in_array($value, $this->choices, true),
            FieldType::Url      => mb_strlen($value) <= 255 && preg_match('#\Ahttps?://[^\s\p{Cc}]+\z#uD', $value) === 1,
            FieldType::Email    => $this->isEmail($value),
        };
        if (!$ok) {
            throw new InvalidArgumentException('field.error.type');
        }

        return $value;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function isEmail(string $value): bool
    {
        try {
            new EmailAddress($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
