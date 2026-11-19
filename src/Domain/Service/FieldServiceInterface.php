<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * Custom field definitions (contract section 6.4), `manage`.
 */
interface FieldServiceInterface
{
    /**
     * Defines a field; its id.
     *
     * @param list<string> $choices
     */
    public function define(SubjectType $type, string $key, string $label, FieldType $fieldType, array $choices, bool $required, int $position): string;

    /**
     * Changes a field shown at `$expectedVersion` (the key and type never change).
     *
     * @param list<string> $choices
     */
    public function change(string $id, string $label, array $choices, bool $required, int $position, int $expectedVersion): void;

    /**
     * Archives (or brings back) a field.
     */
    public function archive(string $id, bool $archived, int $expectedVersion): void;
}
