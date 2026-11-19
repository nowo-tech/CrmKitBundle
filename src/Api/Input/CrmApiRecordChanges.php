<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;

use function array_key_exists;

/**
 * A partial change of a company or contact (0.3.0, `crm_company_update` / `crm_contact_update`; the design's
 * `CrmApiCompanyChanges` / `CrmApiContactChanges` in one VO): each field is present or absent; an absent field keeps
 * its value. Unknown keys are refused.
 */
final readonly class CrmApiRecordChanges implements CrmApiInput
{
    /**
     * @param array<string, string|null> $values the core fields sent
     * @param array<string, bool|float|int|string|null>|null $custom null: not sent
     * @param list<string>|null $roles null: not sent
     * @param list<string>|null $tagIds null: not sent
     * @param CrmApiOwner|null $owner null: not sent
     */
    public function __construct(
        public SubjectType $type,
        public array $values = [],
        public ?array $custom = null,
        public ?array $roles = null,
        public ?array $tagIds = null,
        public ?CrmApiOwner $owner = null,
    ) {
    }

    /**
     * From an input array: the core field names, `custom`, `roles`, `tag_ids` and `owner`.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(SubjectType $type, array $data): self
    {
        CrmApiFields::only($data, [...RecordFields::names($type), 'custom', 'roles', 'tag_ids', 'owner']);
        $values = [];
        foreach (RecordFields::of($type) as $name => [$max]) {
            if (CrmApiFields::has($data, $name)) {
                $values[$name] = CrmApiFields::text($data, $name, $max * 2);
            }
        }

        return new self(
            $type,
            $values,
            CrmApiCustom::parse($data),
            CrmApiFields::keys($data, 'roles', RoleKey::PATTERN, 32),
            CrmApiFields::ids($data, 'tag_ids'),
            CrmApiFields::has($data, 'owner') ? CrmApiOwner::parse($data['owner']) : null,
        );
    }

    /**
     * Whether a field was sent (a core field, `custom`, `roles`, `tag_ids` or `owner`).
     */
    public function has(string $field): bool
    {
        return match ($field) {
            'custom'  => $this->custom !== null,
            'roles'   => $this->roles !== null,
            'tag_ids' => $this->tagIds !== null,
            'owner'   => $this->owner instanceof CrmApiOwner,
            default   => array_key_exists($field, $this->values),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['type' => $this->type->value, 'values' => $this->values, 'custom' => $this->custom, 'roles' => $this->roles, 'tag_ids' => $this->tagIds, 'owner' => $this->owner?->canonical()];
    }
}
