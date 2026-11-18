<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\FieldKey;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function mb_strlen;
use function trim;

/**
 * Custom field definitions (contract section 6.4): the key is unique per tenant and type, the number per type is
 * bounded (`fields.max_per_subject`), changes carry the version. Stateless.
 */
final readonly class FieldService implements FieldServiceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private int $maxPerSubject = 50)
    {
    }

    /**
     * Defines a field.
     */
    public function define(SubjectType $type, string $key, string $label, FieldType $fieldType, array $choices, bool $required, int $position): string
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        try {
            $fieldKey = new FieldKey($key);
        } catch (InvalidArgumentException) {
            throw InvalidInputException::field('key', 'error.field_key');
        }
        if (array_key_exists($key, RecordFields::of($type))) {
            throw InvalidInputException::field('key', 'error.field_key_reserved');
        }
        [$label, $choices] = $this->clean($label, $fieldType, $choices);

        try {
            return $this->support->write(function (Effects $effects) use ($context, $type, $fieldKey, $label, $fieldType, $choices, $required, $position): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $existing = $this->support->store->fields($context->tenant, $type, true);
                foreach ($existing as $field) {
                    if ($field->getFieldKey() === $fieldKey->value) {
                        throw InvalidInputException::field('key', 'error.field_key_taken');
                    }
                }
                if (count($existing) >= $this->maxPerSubject) {
                    throw InvalidInputException::field('key', 'error.field_limit');
                }
                $id = $this->support->id();
                $this->support->store->add(new FieldDefinition($id, $context->tenant->value, $type, $fieldKey, $label, $fieldType, $choices, $required, $position, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $this->support->audit($effects, $context, 'crm.field.defined', $type, $id);

                return $id;
            });
        } catch (DuplicateRowException) {
            throw InvalidInputException::field('key', 'error.field_key_taken');
        }
    }

    /**
     * Changes a field.
     */
    public function change(string $id, string $label, array $choices, bool $required, int $position, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        $this->support->write(function (Effects $effects) use ($context, $id, $label, $choices, $required, $position, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $field             = $this->field($context, $id, $expectedVersion);
            [$label, $choices] = $this->clean($label, $field->getType(), $choices);
            $field->change($label, $choices, $required, $position);
            $this->support->audit($effects, $context, 'crm.field.changed', $field->getSubjectType(), $id);
        });
    }

    /**
     * Archives or brings back a field.
     */
    public function archive(string $id, bool $archived, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        $this->support->write(function (Effects $effects) use ($context, $id, $archived, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $field = $this->field($context, $id, $expectedVersion);
            $field->setArchived($archived); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->audit($effects, $context, $archived ? 'crm.field.archived' : 'crm.field.restored', $field->getSubjectType(), $id);
        });
    }

    private function field(CrmContext $context, string $id, int $expectedVersion): FieldDefinition
    {
        $field = RecordId::isValid($id) ? $this->support->store->field($context->tenant, $id) : null;
        if (!$field instanceof FieldDefinition) {
            throw new NotAvailableException();
        }
        if ($field->getVersion() !== $expectedVersion) {
            throw new ConflictException(ConflictException::STALE, $id);
        }

        return $field;
    }

    /**
     * The trimmed label and choices, validated.
     *
     * @param list<string> $choices
     *
     * @return array{string, list<string>}
     */
    private function clean(string $label, FieldType $type, array $choices): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80 || RecordValidator::check($label, 80, 'text') !== null) {
            throw InvalidInputException::field('label', 'error.invalid');
        }
        if ($type !== FieldType::Choice) {
            return [$label, []];
        }
        $clean = [];
        foreach ($choices as $choice) {
            $choice = trim($choice);
            if ($choice === '') {
                continue;
            }
            if (mb_strlen($choice) > 80 || RecordValidator::check($choice, 80, 'text') !== null) {
                throw InvalidInputException::field('choices', 'error.invalid');
            }
            $clean[] = $choice;
        }
        $clean = array_values(array_unique($clean));
        if ($clean === [] || count($clean) > 50) {
            throw InvalidInputException::field('choices', 'error.field_choices');
        }

        return [$label, $clean];
    }
}
