<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A custom field definition.
 */
final readonly class FieldView
{
    /**
     * @param list<string> $choices
     */
    public function __construct(
        public string $id,
        public SubjectType $subjectType,
        public string $key,
        public string $label,
        public FieldType $type,
        public array $choices,
        public bool $required,
        public int $position,
        public bool $archived,
        public int $version,
    ) {
    }
}
