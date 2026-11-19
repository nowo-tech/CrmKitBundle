<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function mb_strlen;
use function preg_match;
use function trim;

/**
 * An existing tag of the tenant (0.3.0), by id or by its exact name key (creating a tag is settings, never exposed).
 */
final readonly class CrmApiTagRef implements CrmApiInput
{
    private function __construct(public ?RecordId $id, public ?string $name)
    {
    }

    /** By id. */
    public static function byId(RecordId $id): self
    {
        return new self($id, null);
    }

    /**
     * By name (matched by the tag name key exactly).
     *
     * @throws CrmApiRefusal `INPUT_002` (field `tag`)
     */
    public static function byName(string $name): self
    {
        if (preg_match('//u', $name) !== 1 || trim($name) === '' || mb_strlen($name) > 120) {
            throw CrmApiRefusal::malformed('tag');
        }

        return new self(null, $name);
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['tag_id' => $this->id?->value, 'tag_name' => $this->name];
    }
}
