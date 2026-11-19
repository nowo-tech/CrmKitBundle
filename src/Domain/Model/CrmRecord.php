<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use LogicException;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

use function array_key_exists;
use function sprintf;

/**
 * What companies and contacts share (contract section 6.2): tenant, owner, custom values, status, address, the
 * optimistic version and the stamps. Plain PHP, mapped as a mapped superclass in XML; the host references records by
 * id only.
 */
abstract class CrmRecord
{
    protected ?string $email = null;

    protected ?string $phone = null;

    protected ?string $street = null;

    protected ?string $postalCode = null;

    protected ?string $city = null;

    protected ?string $region = null;

    protected ?string $country = null;

    protected ?string $ownerId = null;

    /** @var array<string, string> */
    protected array $custom = [];

    protected RecordStatus $status = RecordStatus::Active;

    protected ?string $mergedIntoId = null;

    protected ?DateTimeImmutable $trashedAt = null;

    protected int $version = 1;

    protected DateTimeImmutable $updatedAt;

    protected ?string $updatedById = null;

    /**
     * @param string $id the row id
     * @param string $tenantId the tenant
     */
    public function __construct(
        protected string $id,
        protected string $tenantId,
        protected DateTimeImmutable $createdAt,
        protected ?string $createdById = null,
    ) {
        $this->updatedAt = $createdAt;
    }

    /**
     * The kind of record.
     */
    abstract public static function type(): SubjectType;

    /**
     * What lists and links show (company name, contact display name).
     */
    abstract public function label(): string;

    /**
     * A type-specific field, or throws for an unknown name.
     */
    abstract protected function specific(string $field): ?string;

    /**
     * Sets a type-specific field.
     */
    abstract protected function assignSpecific(string $field, ?string $value): void;

    /**
     * Recomputes the derived keys (name, e-mail, domain, sort keys).
     */
    abstract protected function refreshKeys(): void;

    /**
     * The row id.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The tenant.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * A core field ({@see RecordFields}).
     */
    public function value(string $field): ?string
    {
        return match ($field) {
            'email'       => $this->email,
            'phone'       => $this->phone,
            'street'      => $this->street,
            'postal_code' => $this->postalCode,
            'city'        => $this->city,
            'region'      => $this->region,
            'country'     => $this->country,
            default       => $this->specific($field),
        };
    }

    /**
     * All core fields.
     *
     * @return array<string, string|null>
     */
    public function values(): array
    {
        $values = [];
        foreach (RecordFields::names(static::type()) as $field) {
            $values[$field] = $this->value($field);
        }

        return $values;
    }

    /**
     * Applies validated core values; returns the names of the fields that changed.
     *
     * @param array<string, string|null> $values
     *
     * @return list<string>
     */
    public function apply(array $values): array
    {
        $fields  = RecordFields::of(static::type());
        $changed = [];
        foreach ($values as $field => $value) {
            if (!array_key_exists($field, $fields)) {
                throw new LogicException(sprintf('Unknown field "%s".', $field));
            }
            if ($this->value($field) === $value) {
                continue;
            }
            $changed[] = $field;
            match ($field) {
                'email'       => $this->email      = $value,
                'phone'       => $this->phone      = $value,
                'street'      => $this->street     = $value,
                'postal_code' => $this->postalCode = $value,
                'city'        => $this->city       = $value,
                'region'      => $this->region     = $value,
                'country'     => $this->country    = $value,
                default       => $this->assignSpecific($field, $value),
            };
        }
        $this->refreshKeys();

        return $changed;
    }

    /**
     * The owner (a host actor id).
     */
    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    /**
     * Changes the owner; whether it changed.
     */
    public function changeOwner(?string $ownerId): bool
    {
        if ($ownerId === $this->ownerId) {
            return false;
        }
        $this->ownerId = $ownerId;

        return true;
    }

    /**
     * The custom values (field key => value).
     *
     * @return array<string, string>
     */
    public function getCustom(): array
    {
        return $this->custom;
    }

    /**
     * Replaces the custom values; the keys that changed.
     *
     * @param array<string, string> $custom
     *
     * @return list<string>
     */
    public function changeCustom(array $custom): array
    {
        $changed = [];
        foreach ($custom + $this->custom as $key => $unused) {
            if (($custom[$key] ?? null) !== ($this->custom[$key] ?? null)) {
                $changed[] = 'custom.' . $key;
            }
        }
        ksort($custom);
        $this->custom = $custom;

        return $changed;
    }

    /**
     * The status.
     */
    public function getStatus(): RecordStatus
    {
        return $this->status;
    }

    /**
     * Whether the record is active.
     */
    public function isActive(): bool
    {
        return $this->status === RecordStatus::Active;
    }

    /**
     * The survivor of a merge.
     */
    public function getMergedIntoId(): ?string
    {
        return $this->mergedIntoId;
    }

    /**
     * When it was trashed.
     */
    public function getTrashedAt(): ?DateTimeImmutable
    {
        return $this->trashedAt;
    }

    /**
     * Moves an active record to the trash.
     */
    public function trash(DateTimeImmutable $now, ?string $actor): void
    {
        $this->status    = RecordStatus::Trashed;
        $this->trashedAt = $now;
        $this->touch($now, $actor);
    }

    /**
     * Brings a trashed record back.
     */
    public function restore(DateTimeImmutable $now, ?string $actor): void
    {
        $this->status    = RecordStatus::Active;
        $this->trashedAt = null;
        $this->touch($now, $actor);
    }

    /**
     * Turns the record into a tombstone pointing at the survivor of a merge (contract section 16.3): every personal
     * value is removed; id, tenant, status and the survivor stay. The merge trace (`crm_merge`) keeps the snapshot.
     */
    public function mergeInto(string $survivorId, DateTimeImmutable $now, ?string $actor): void
    {
        $this->status       = RecordStatus::Merged;
        $this->mergedIntoId = $survivorId;
        $this->blank();
        $this->releaseKeys();
        $this->touch($now, $actor);
    }

    /**
     * Removes what is left of a merge tombstone's personal data (erasure of its survivor); a no-op on an already
     * blank tombstone except the change mark.
     */
    public function anonymise(DateTimeImmutable $now, ?string $actor): void
    {
        $this->blank();
        $this->releaseKeys();
        $this->touch($now, $actor);
    }

    /**
     * Removes every personal value: the fields, the custom values and the owner.
     */
    protected function blank(): void
    {
        foreach (RecordFields::names(static::type()) as $field) {
            $this->apply([$field => null]);
        }
        $this->custom  = [];
        $this->ownerId = null;
    }

    /**
     * Frees the unique keys (merge, erasure).
     */
    protected function releaseKeys(): void
    {
    }

    /**
     * Marks a change (and makes Doctrine bump the version).
     */
    public function touch(DateTimeImmutable $now, ?string $actor): void
    {
        // Always a change of the stored row, so the optimistic version moves on every write of the record: the ORM
        // compares the converted column values, the column keeps whole seconds, and two writes in the same second by
        // the same person would otherwise change nothing. A write in the same second as the previous one moves the
        // edit time one second on.
        $this->updatedAt   = $now > $this->updatedAt ? $now : $this->updatedAt->modify('+1 second');
        $this->updatedById = $actor;
    }

    /**
     * The optimistic version.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * When it was created.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Who created it.
     */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    /**
     * When it last changed.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Who changed it last.
     */
    public function getUpdatedById(): ?string
    {
        return $this->updatedById;
    }
}
