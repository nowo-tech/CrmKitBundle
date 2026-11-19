<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;

/**
 * What a form, an import or a host sends to create or edit a company or contact. Raw values: the service trims,
 * validates and normalises them.
 */
final readonly class RecordInput
{
    /**
     * @param array<string, string|null> $values core fields ({@see RecordFields}); a missing key keeps the value
     * @param array<string, mixed> $custom custom field key => raw value; null = keep the current values
     * @param list<string>|null $tagIds the record's tags; null = keep them
     * @param bool $setOwner whether `$owner` applies (false keeps the current owner)
     * @param list<string>|null $roles the record's party roles (keys of `roles.catalogue`); null = keep them. Stored
     *                                 roles no longer in the catalogue are kept either way
     */
    public function __construct(
        public SubjectType $type,
        public array $values,
        public ?string $owner = null,
        public ?array $custom = null,
        public ?array $tagIds = null,
        public bool $setOwner = true,
        public ?array $roles = null,
    ) {
    }
}
