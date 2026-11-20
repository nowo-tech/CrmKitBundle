<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;

/**
 * A new company or contact (0.3.0, `crm_company_create` / `crm_contact_create`; the design's
 * `CrmApiCompanyDraft` / `CrmApiContactDraft` in one VO): the core fields of {@see RecordFields} (untrusted text),
 * custom values, roles, tags and the owner (default `me`); a contact can also name a company to be affiliated to. The
 * services validate the values as the forms do.
 */
final readonly class CrmApiRecordDraft implements CrmApiInput
{
    /** The owner. */
    public CrmApiOwner $owner;

    /**
     * @param array<string, string|null> $values core fields
     * @param array<string, bool|float|int|string|null>|null $custom custom field key => value
     * @param list<string> $roles party role keys
     * @param list<string> $tagIds tag ids of the tenant
     */
    public function __construct(
        public SubjectType $type,
        public array $values,
        public ?array $custom = null,
        public array $roles = [],
        public array $tagIds = [],
        ?CrmApiOwner $owner = null,
        public ?CrmApiCompanyLink $company = null,
    ) {
        $this->owner = $owner ?? CrmApiOwner::me();
    }

    /**
     * From an input array: the core field names, `custom`, `roles`, `tag_ids`, `owner` and (contacts) `company`.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(SubjectType $type, array $data): self
    {
        $fields = RecordFields::names($type);
        CrmApiFields::only($data, [...$fields, 'custom', 'roles', 'tag_ids', 'owner', ...($type === SubjectType::Contact ? ['company'] : [])]);
        $values = [];
        foreach (RecordFields::of($type) as $name => [$max]) {
            if (CrmApiFields::has($data, $name)) {
                $values[$name] = CrmApiFields::text($data, $name, $max * 2);
            }
        }
        $company = CrmApiFields::object($data, 'company');

        return new self(
            $type,
            $values,
            CrmApiCustom::parse($data),
            CrmApiFields::keys($data, 'roles', RoleKey::PATTERN, 32) ?? [],
            CrmApiFields::ids($data, 'tag_ids') ?? [],
            CrmApiFields::has($data, 'owner') ? CrmApiOwner::parse($data['owner']) : null,
            $company === null ? null : CrmApiCompanyLink::fromArray($company, null, 'company.'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['type' => $this->type->value, 'values' => $this->values, 'custom' => $this->custom, 'roles' => $this->roles, 'tag_ids' => $this->tagIds, 'owner' => $this->owner->canonical(), 'company' => $this->company?->canonical()];
    }
}
