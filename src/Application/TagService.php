<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\TagsChanged;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TagName;

use function in_array;
use function trim;

/**
 * Tags (contract sections 7 and 15): the lowercase name is unique per tenant (a racing duplicate is a form error),
 * renames carry the version. Stateless.
 */
final readonly class TagService implements TagServiceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private Taggings $taggings)
    {
    }

    /**
     * A copy bound to `$caller` for one call (0.3.0: an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->support->as($caller), $this->taggings->as($caller));
    }

    /**
     * Creates a tag.
     */
    public function create(string $name, string $colour): string
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        $tagName = $this->name($name);
        $this->colour($colour);

        try {
            return $this->support->write(function (Effects $effects) use ($context, $tagName, $colour): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $this->refuseTaken($context, $tagName, null);
                $id = $this->support->id();
                $this->support->store->add(new Tag($id, $context->tenant->value, $tagName, $colour, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $this->support->audit($effects, $context, 'crm.tag.created', null, $id);

                return $id;
            });
        } catch (DuplicateRowException) {
            throw InvalidInputException::field('name', 'error.tag_taken');
        }
    }

    /**
     * Renames / recolours a tag.
     */
    public function change(string $id, string $name, string $colour, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        $tagName = $this->name($name);
        $this->colour($colour);

        try {
            $this->support->write(function (Effects $effects) use ($context, $id, $tagName, $colour, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $tag = $this->tag($context, $id);
                if ($tag->getVersion() !== $expectedVersion) {
                    throw new ConflictException(ConflictException::STALE, $id);
                }
                $this->refuseTaken($context, $tagName, $id);
                $tag->change($tagName, $colour);
                $this->support->audit($effects, $context, 'crm.tag.changed', null, $id);
            });
        } catch (DuplicateRowException) {
            throw InvalidInputException::field('name', 'error.tag_taken');
        }
    }

    /**
     * Deletes a tag and its taggings.
     */
    public function delete(string $id): void
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        $this->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $tag   = $this->tag($context, $id);
            $count = 0;
            foreach ($this->support->store->taggingsOfTag($context->tenant, $id) as $tagging) {
                $this->support->store->remove($tagging); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                ++$count;
            }
            $this->support->store->remove($tag); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->audit($effects, $context, 'crm.tag.deleted', null, $id, ['taggings' => $count]);
        });
    }

    /**
     * Sets a record's tags.
     */
    public function assign(SubjectType $type, string $id, array $tagIds): void
    {
        $context = $this->support->access->base();
        if (!Taggings::distinct($tagIds)) {
            throw InvalidInputException::field('tags', 'error.invalid');
        }
        $work = function (Effects $effects) use ($context, $type, $id, $tagIds): void {
            $record            = $this->support->load($context, $type, $id, CrmPermission::Edit, lock: true);
            [$added, $removed] = $this->taggings->set($context, $type, $id, $tagIds, $effects); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            if ($added !== [] || $removed !== []) {
                $record->touch($context->now, $context->actor->value);
                $effects->event(new TagsChanged($context->tenant, $context->actor, $context->now, $type, $id, $added, $removed));
            }
        };

        try {
            $this->support->write($work);
        } catch (DuplicateRowException) {
            // A racing writer added the same tag: once more, against its rows.
            RecordService::onceMore(fn () => $this->support->write($work));
        }
    }

    private function tag(CrmContext $context, string $id): Tag
    {
        $tag = RecordId::isValid($id) ? $this->support->store->tag($context->tenant, $id) : null;
        if (!$tag instanceof Tag) {
            throw new NotAvailableException();
        }

        return $tag;
    }

    private function refuseTaken(CrmContext $context, TagName $name, ?string $except): void
    {
        foreach ($this->support->store->tags($context->tenant) as $tag) {
            if ($tag->getNameKey() === $name->key() && $tag->getId() !== $except) {
                throw InvalidInputException::field('name', 'error.tag_taken');
            }
        }
    }

    private function name(string $name): TagName
    {
        try {
            return new TagName(trim($name));
        } catch (InvalidArgumentException) {
            throw InvalidInputException::field('name', 'error.tag_name');
        }
    }

    private function colour(string $colour): void
    {
        if (!in_array($colour, Tag::COLOURS, true)) {
            throw InvalidInputException::field('colour', 'error.invalid');
        }
    }
}
