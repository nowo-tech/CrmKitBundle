<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\TagName;

/**
 * A tag of the tenant; its lowercase name is unique per tenant.
 */
class Tag
{
    /** UiKit palette tokens a tag may use. */
    public const COLOURS = ['neutral', 'blue', 'green', 'yellow', 'orange', 'red', 'purple', 'teal'];

    private string $name;

    private string $nameKey;

    private int $version = 1;

    /**
     * @param string $colour one of {@see self::COLOURS}
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        TagName $name,
        private string $colour,
        private DateTimeImmutable $createdAt,
    ) {
        $this->name    = $name->value;
        $this->nameKey = $name->key();
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

    /** The name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** The lowercase name. */
    public function getNameKey(): string
    {
        return $this->nameKey;
    }

    /** The palette token. */
    public function getColour(): string
    {
        return $this->colour;
    }

    /** Renames and recolours it. */
    public function change(TagName $name, string $colour): void
    {
        $this->name    = $name->value;
        $this->nameKey = $name->key();
        $this->colour  = $colour;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
