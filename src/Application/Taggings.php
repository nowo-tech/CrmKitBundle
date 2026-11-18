<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;

use function count;
use function in_array;

/**
 * Sets the tags of a record inside a running unit (set semantics; contract section 15: the unique `(tag, subject)` key
 * makes a racing duplicate a replay, never a second row). Stateless.
 *
 * @internal
 */
final readonly class Taggings
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support)
    {
    }

    /**
     * Replaces the record's tags; the added and removed tag ids.
     *
     * @param list<string> $tagIds
     *
     * @throws InvalidInputException when a tag is not one of the tenant's
     *
     * @return array{list<string>, list<string>}
     */
    public function set(CrmContext $context, SubjectType $type, string $id, array $tagIds): array
    {
        $store = $this->support->store;
        $known = [];
        foreach ($store->tags($context->tenant) as $tag) {
            $known[$tag->getId()] = $tag;
        }
        $wanted = [];
        foreach ($tagIds as $tagId) {
            if (!($known[$tagId] ?? null) instanceof Tag) {
                throw InvalidInputException::field('tags', 'error.tag_unknown');
            }
            $wanted[$tagId] = true;
        }
        $current = [];
        $removed = [];
        foreach ($store->taggings($context->tenant, $type, [$id]) as $tagging) {
            $current[$tagging->getTagId()] = true;
            if (!isset($wanted[$tagging->getTagId()])) {
                $store->remove($tagging); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $removed[] = $tagging->getTagId();
            }
        }
        $added = [];
        foreach ($wanted as $tagId => $unused) {
            if (!isset($current[$tagId])) {
                $store->add(new Tagging($this->support->id(), $context->tenant->value, $tagId, $type, $id, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $added[] = $tagId;
            }
        }
        if ($added !== []) {
            $this->support->activity($context, $type, $id, ActivityKind::Tagged, ['count' => count($added)]);
        }
        if ($removed !== []) {
            $this->support->activity($context, $type, $id, ActivityKind::Untagged, ['count' => count($removed)]);
        }

        return [$added, $removed];
    }

    /**
     * Whether the list holds only distinct strings.
     *
     * @param list<string> $ids
     */
    public static function distinct(array $ids): bool
    {
        $seen = [];
        foreach ($ids as $id) {
            if (in_array($id, $seen, true)) {
                return false;
            }
            $seen[] = $id;
        }

        return true;
    }
}
