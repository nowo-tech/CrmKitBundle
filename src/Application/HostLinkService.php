<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\HostLinkAdded;
use Nowo\CrmKitBundle\Domain\Event\HostLinkRemoved;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TimelineInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function count;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function str_starts_with;
use function trim;

/**
 * Host links and host timeline entries (contract sections 6.5 and 6.6). A link is unique per record and host object,
 * so a double add (or a racing one) stores one row. Stateless.
 */
final readonly class HostLinkService implements HostLinkServiceInterface, TimelineInterface
{
    /**
     * @param iterable<CrmHostObjectResolverInterface> $resolvers the host's resolvers (tag `nowo_crm_kit.host_object`)
     */
    public function __construct(private WriteSupport $support, private iterable $resolvers = [])
    {
    }

    /**
     * A copy bound to `$caller` for one call (0.3.0: an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->support->as($caller), $this->resolvers);
    }

    /**
     * Links a record to a host object.
     */
    public function link(SubjectType $type, string $subjectId, HostObjectRef $host, ?string $label = null): string
    {
        $context = $this->support->access->base();
        $label   = $label === null || trim($label) === '' ? null : trim($label);
        if ($label !== null && RecordValidator::check($label, 200, 'text') !== null) {
            throw InvalidInputException::field('label', 'error.invalid');
        }
        // A type with a resolver links only objects the resolver confirms (in the tenant, visible to the actor,
        // byte-exact id); a type without one is a free reference.
        $resolver = $this->resolver($host->type);
        if ($resolver instanceof CrmHostObjectResolverInterface && ($resolver->resolve($context->tenant, [$host->id])[$host->id] ?? null)?->id !== $host->id) {
            throw InvalidInputException::field('hostId', 'error.host_object_unknown');
        }
        $work = function (Effects $effects) use ($context, $type, $subjectId, $host, $label): string {
            $this->support->load($context, $type, $subjectId, CrmPermission::Edit, lock: true);
            foreach ($this->support->store->links($context->tenant, $type, $subjectId) as $link) {
                if ($link->getHostType() === $host->type && $link->getHostId() === $host->id) {
                    return $link->getId();
                }
            }
            $id = $this->support->id();
            $this->support->store->add(new HostLink($id, $context->tenant->value, $type, $subjectId, $host->type, $host->id, $label, $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, $type, $subjectId, ActivityKind::Linked, ['host_type' => $host->type]);
            $this->support->audit($effects, $context, 'crm.link.added', $type, $subjectId, ['host_type' => $host->type]);
            $effects->event(new HostLinkAdded($context->tenant, $context->actor, $context->now, $type, $subjectId, $host->type, $host->id));

            return $id;
        };

        try {
            return $this->support->write($work);
        } catch (DuplicateRowException) {
            return RecordService::onceMore(fn (): string => $this->support->write($work));
        }
    }

    /**
     * Removes a link.
     */
    public function unlink(string $linkId): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $linkId): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $link = RecordId::isValid($linkId) ? $this->support->store->link($context->tenant, $linkId) : null;
            if (!$link instanceof HostLink) {
                throw new NotAvailableException();
            }
            $this->support->load($context, $link->getSubjectType(), $link->getSubjectId(), CrmPermission::Edit, lock: true);
            $this->support->store->remove($link); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, $link->getSubjectType(), $link->getSubjectId(), ActivityKind::Unlinked, ['host_type' => $link->getHostType()]);
            $this->support->audit($effects, $context, 'crm.link.removed', $link->getSubjectType(), $link->getSubjectId(), ['host_type' => $link->getHostType()]);
            $effects->event(new HostLinkRemoved($context->tenant, $context->actor, $context->now, $link->getSubjectType(), $link->getSubjectId(), $link->getHostType(), $link->getHostId()));
        });
    }

    /**
     * Active records linked to a host object.
     */
    public function subjectsLinkedTo(HostObjectRef $host): array
    {
        $context = $this->support->access->context(CrmPermission::View);

        return $this->support->read(function () use ($context, $host): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subjects = [];
            $owners   = $this->support->access->visibleOwners($context);
            foreach ($this->support->store->linksTo($context->tenant, $host) as $link) {
                $record = $this->support->store->record($link->getSubjectType(), $context->tenant, $link->getSubjectId());
                if ($record instanceof CrmRecord && $record->isActive() && CrmAccess::visible($owners, $record->getOwnerId())) {
                    $subjects[] = SubjectRef::of($link->getSubjectType(), $record->getId(), $record->getOwnerId());
                }
            }

            return $subjects;
        });
    }

    /**
     * The resolver of a host object type, if the host has one.
     */
    private function resolver(string $type): ?CrmHostObjectResolverInterface
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->type() === $type) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * Whether every parameter key is a plain word (`^[a-z][a-z0-9_]{0,39}$`).
     *
     * @param array<string, mixed> $params
     */
    private function keys(array $params): bool
    {
        foreach ($params as $key => $unused) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', (string) $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * A host entry on a record's timeline.
     */
    public function record(SubjectType $type, string $subjectId, string $labelKey, array $params = [], ?string $url = null): void
    {
        $context = $this->support->access->base();
        if (preg_match('/\A[a-z0-9_.-]{1,120}\z/D', $labelKey) !== 1 || count($params) > 20 || !$this->keys($params) || ($url !== null && (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_starts_with($url, '/\\') || mb_strlen($url) > 500))) {
            throw InvalidInputException::field('_', 'error.invalid');
        }
        foreach ($params as $value) {
            if (!(is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) || (is_string($value) && mb_strlen($value) > 200)) {
                throw InvalidInputException::field('_', 'error.invalid');
            }
        }
        $this->support->write(function (Effects $effects) use ($context, $type, $subjectId, $labelKey, $params, $url): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->load($context, $type, $subjectId, CrmPermission::Edit, lock: true);
            $translation = [];
            foreach ($params as $key => $value) {
                $translation['%' . $key . '%'] = $value;
            }
            $this->support->activity($context, $type, $subjectId, ActivityKind::Host, ['_label' => $labelKey, '_url' => $url] + $translation);
            $this->support->audit($effects, $context, 'crm.timeline.host_entry', $type, $subjectId, ['label' => $labelKey]);
        });
    }
}
