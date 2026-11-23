<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotReaderInterface;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotList;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotProperty;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotPropertyView;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotSchemaView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\HubSpotImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TagName;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * {@see HubSpotImportServiceInterface} (0.10.0, design sections 5.1, 10 and 12): HubSpot is asked outside every
 * transaction; `start()` refuses a second open HubSpot import of the tenant under the connection row's lock; `confirm()`
 * creates the tags of "create" lists, their list references and the claim in one unit (a lost claim rolls the tags
 * back). Runs are {@see HubSpotRunner}'s, through `ImportServiceInterface::run()`. Stateless.
 */
final readonly class HubSpotImportService implements HubSpotImportServiceInterface
{
    /** Scope a token needs to read lists. */
    public const LISTS_SCOPE = 'crm.lists.read';

    /**
     * Wires the collaborators.
     *
     * @param bool $queued `import.messenger.enabled`: a confirmed import is left unclaimed and due now, for the
     *                     background run
     * @param int $browseLimit `import.hubspot.browse_rate_limit.limit` (0.10.1, audit ZAM-9): HubSpot reads of the
     *                         start and mapping pages per tenant in a window
     * @param int $browseInterval `import.hubspot.browse_rate_limit.interval`: the window in seconds
     */
    public function __construct(
        private WriteSupport $support,
        private HubSpotStoreInterface $store,
        private HubSpotReaderInterface $reader,
        private HubSpotTokenOpener $tokens,
        private bool $queued = false,
        private int $browseLimit = 60,
        private int $browseInterval = 600,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function lists(): array
    {
        $context              = $this->support->access->context(CrmPermission::Import);
        [$connection, $token] = $this->open($context, true);
        if (!in_array(self::LISTS_SCOPE, $connection->getScopes(), true)) {
            return [];
        }

        try {
            $lists = $this->reader->lists($token);
        } catch (HubSpotException $e) {
            throw HubSpotErrors::input('lists', $e);
        }

        return array_values(array_filter($lists, static fn (HubSpotList $l): bool => $l->object instanceof HubSpotObject));
    }

    /**
     * {@inheritDoc}
     */
    public function start(HubSpotImportScope $scope, DuplicatePolicy $policy = DuplicatePolicy::Skip): string
    {
        $context              = $this->support->access->context(CrmPermission::Import);
        [$connection, $token] = $this->open($context, true);
        $scopes               = [];
        foreach ($scope->objects as $object) {
            $scopes = [...$scopes, ...$object->scopes()];
        }
        if ($scope->lists !== []) {
            $scopes[] = self::LISTS_SCOPE;
        }
        foreach ($scopes as $needed) {
            if (!in_array($needed, $connection->getScopes(), true)) {
                throw InvalidInputException::field('objects', 'error.hubspot_scope_missing');
            }
        }

        try {
            $total = 0;
            foreach ($scope->objects as $object) {
                $total += $this->reader->count($token, $object);
            }
            $kinds = [];
            foreach ($scope->lists === [] ? [] : $this->reader->lists($token, $scope->lists) as $list) {
                $kinds[$list->id->value] = $list->object;
            }
            $defaults = [];
            foreach ($scope->objects as $object) {
                $defaults[$object->value] = HubSpotMapper::defaults($object, $this->reader->properties($token, $object));
            }
        } catch (HubSpotException $e) {
            throw HubSpotErrors::input('objects', $e);
        }
        foreach ($scope->lists as $list) {
            if (!($kinds[$list->value] ?? null) instanceof HubSpotObject || !$scope->reads($kinds[$list->value])) {
                throw InvalidInputException::field('lists', 'error.hubspot_list_unknown');
            }
        }
        $state = HubSpotRunState::start($connection->getAccountId(), $scope, $kinds);

        return $this->support->write(function (Effects $effects) use ($context, $connection, $scope, $policy, $total, $defaults, $state): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            // Under the connection row's lock: two starts of the tenant are serialised, the second sees the first.
            $locked = $this->store->lockConnection($context->tenant);
            if (!$locked instanceof HubSpotConnection || $locked->getId() !== $connection->getId()) {
                throw InvalidInputException::field('_', 'error.hubspot_not_connected');
            }
            if ($this->store->hasOpenImport($context->tenant)) {
                throw new ConflictException(ConflictException::IMPORT_RUNNING);
            }
            $id     = $this->support->id();
            $import = new Import($id, $context->tenant->value, $scope->objects[0]->subjectType(), ImportFormat::HubSpot, $locked->getLabel(), $scope->columns(), (new HubSpotMapping($defaults))->flat(), $total, $context->actor->value, $context->now, $locked->getId());
            $import->startCursor($state->toArray());
            $import->choosePolicy($policy);
            $this->support->store->add($import); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->audit($effects, $context, 'crm.hubspot.import_started', $scope->objects[0]->subjectType(), $id, ['objects' => count($scope->objects), 'lists' => count($scope->lists), 'rows' => $total]);

            return $id;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function schema(string $importId): HubSpotSchemaView
    {
        $context         = $this->support->access->context(CrmPermission::Import);
        $import          = $this->support->read(fn (): Import => $this->import($context->tenant, $importId));
        [, $token]       = $this->open($context, true);
        $scope           = $this->scope($import);
        $mapping         = $this->mapping($import);
        [$fields, $tags] = $this->support->read(fn (): array => [$this->customKeys($context->tenant, $scope), array_map(static fn (Tag $t): TagView => new TagView($t->getId(), $t->getName(), $t->getColour(), $t->getVersion()), $this->support->store->tags($context->tenant))]);
        $properties      = [];

        try {
            foreach ($scope->objects as $object) {
                $targets = HubSpotMapper::targets($object, $fields[$object->value] ?? []);
                $chosen  = $mapping->of($object);
                $rows    = [];
                foreach ($this->reader->properties($token, $object) as $property) {
                    $rows[] = new HubSpotPropertyView($property->name->value, $property->label, $property->type, $targets, $chosen[$property->name->value] ?? '');
                }
                $properties[$object->value] = $rows;
            }
            $lists = [];
            foreach ($scope->lists === [] ? [] : $this->reader->lists($token, $scope->lists) as $list) {
                $lists[] = ['list' => $list, 'tag' => $mapping->lists[$list->id->value] ?? HubSpotMapping::CREATE];
            }
        } catch (HubSpotException $e) {
            throw HubSpotErrors::input('mapping', $e);
        }

        return new HubSpotSchemaView($import->getId(), $properties, $lists, $tags, $import->getVersion());
    }

    /**
     * {@inheritDoc}
     */
    public function confirm(string $importId, HubSpotMapping $mapping, DuplicatePolicy $policy, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Import);
        // Audit ZAM-1: a re-run updates the records an earlier run linked whatever the policy, and unattended pages run
        // as the person who confirms: that person may edit.
        $this->support->access->require($context, CrmPermission::Edit);
        $import = $this->support->read(fn (): Import => $this->import($context->tenant, $importId));
        $scope  = $this->scope($import);
        $keys   = $this->support->read(fn (): array => $this->customKeys($context->tenant, $scope));
        foreach ($mapping->properties as $object => $map) {
            $kind = HubSpotObject::from((string) $object);
            if (!$scope->reads($kind) || !$mapping->customKnown($kind, $keys[$kind->value] ?? [])) {
                throw InvalidInputException::field('mapping', 'import.error.mapping');
            }
        }
        [, $token] = $this->open($context);

        try {
            foreach ($mapping->properties as $object => $map) {
                // Only properties HubSpot offers (never a sensitive one: a page asking for it would answer 403).
                $known = array_map(static fn (HubSpotProperty $p): string => $p->name->value, $this->reader->properties($token, HubSpotObject::from((string) $object)));
                foreach ($map as $property => $target) {
                    if ($target !== '' && !in_array((string) $property, $known, true)) {
                        throw InvalidInputException::field('mapping', 'import.error.mapping');
                    }
                }
            }
        } catch (HubSpotException $e) {
            throw HubSpotErrors::input('mapping', $e);
        }
        $chosen = array_map(static fn (HubSpotObjectId $l): string => $l->value, $scope->lists);
        $names  = [];
        foreach ($mapping->lists as $list => $tag) {
            if (!in_array((string) $list, $chosen, true)) {
                throw InvalidInputException::field('mapping', 'import.error.mapping');
            }
        }
        if (in_array(HubSpotMapping::CREATE, $mapping->lists, true)) {
            try {
                foreach ($this->reader->lists($token, $scope->lists) as $list) {
                    $names[$list->id->value] = $list->name;
                }
            } catch (HubSpotException $e) {
                throw HubSpotErrors::input('mapping', $e);
            }
        }

        try {
            $this->support->write(function (Effects $effects) use ($context, $import, $mapping, $policy, $expectedVersion, $names): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $state = HubSpotRunState::fromArray($import->getCursor());
                $lists = [];
                foreach ($mapping->lists as $list => $choice) {
                    $lists[(string) $list] = $this->tagOf($context, $state->account, (string) $list, $choice, $names[(string) $list] ?? null);
                }
                $resolved = new HubSpotMapping($mapping->properties, $lists);
                $token    = $this->support->id();
                if (!$this->support->store->claimImport($context->tenant, $import->getId(), $expectedVersion, $token, json_encode($resolved->flat(), JSON_THROW_ON_ERROR), $policy->value, $context->now)) {
                    // Another confirm won: the tags created here roll back with this unit.
                    throw new ConflictException(ConflictException::IMPORT_RUNNING, $import->getId());
                }
                $this->store->confirmedBy($import->getId(), $context->actor->value);
                if ($this->queued) {
                    // import.messenger.enabled: the background run takes it (ImportService::run() dispatches it).
                    $this->support->store->queueImport($import->getId(), $token, $context->now);
                }
                $this->support->audit($effects, $context, 'crm.hubspot.import_confirmed', $import->getSubjectType(), $import->getId(), ['policy' => $policy->value, 'lists' => count($lists)]);
            });
        } catch (DuplicateRowException $e) {
            // A tag of the same name, or the list's reference, was stored by another writer at the same moment.
            throw new ConflictException(ConflictException::WRITE_CONFLICT, null, $e);
        }
    }

    /**
     * The tag a list's members get: the chosen existing tag, or for "create" the tag of an earlier run of the list
     * (its reference), else the tenant's tag of the same name, else a new tag named after the list. A new or found
     * tag gets the list's reference.
     */
    private function tagOf(CrmContext $context, string $account, string $list, string $choice, ?string $name): string
    {
        $store = $this->support->store;
        if ($choice !== HubSpotMapping::CREATE) {
            if (!$store->tag($context->tenant, $choice) instanceof Tag) {
                throw InvalidInputException::field('mapping', 'error.tag_unknown');
            }

            return $choice;
        }
        $ref = $this->store->refs($context->tenant, $account, 'list', [$list])[$list] ?? null;
        if ($ref instanceof ExternalRef && $store->tag($context->tenant, $ref->getTargetId()) instanceof Tag) {
            return $ref->getTargetId();
        }

        try {
            $tagName = new TagName((string) $name);
        } catch (InvalidArgumentException) {
            throw InvalidInputException::field('mapping', 'error.hubspot_list_name');
        }
        $tag = null;
        foreach ($store->tags($context->tenant) as $existing) {
            $tag = $existing->getNameKey() === $tagName->key() ? $existing : $tag;
        }
        if (!$tag instanceof Tag) {
            $tag = new Tag($this->support->id(), $context->tenant->value, $tagName, 'neutral', $context->now);
            $store->add($tag); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($ref instanceof ExternalRef) {
            $this->store->retarget($ref, $tag->getId(), $context->now);
        } else {
            $store->add(new ExternalRef($this->support->id(), $context->tenant->value, $account, 'list', $list, 'tag', $tag->getId(), $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }

        return $tag->getId();
    }

    /**
     * The tenant's connection and its token, or the input error when there is none, it is invalid, its token does not
     * open or its key is not available (0.10.1: nothing is changed, audit ZAM-D2). `$browse`: a read of the start or
     * mapping page, counted against `import.hubspot.browse_rate_limit` in its own unit before HubSpot is asked (audit
     * ZAM-9: reloads cannot burn the portal's shared daily quota).
     *
     * @return array{HubSpotConnection, HubSpotToken}
     */
    private function open(CrmContext $context, bool $browse = false): array
    {
        $connection = $this->support->read(fn (): ?HubSpotConnection => $this->store->connection($context->tenant));
        if (!$connection instanceof HubSpotConnection) {
            throw InvalidInputException::field('_', 'error.hubspot_not_connected');
        }
        if ($browse && !$this->support->write(fn (): bool => $this->store->takeBrowseCall($context->tenant, $context->now, $this->browseLimit, $this->browseInterval))) {
            throw InvalidInputException::field('_', 'error.hubspot_browse_limited');
        }

        try {
            $token = $connection->isActive() ? $this->tokens->open($context->tenant, $connection, $context->now) : null;
        } catch (SecretKeyUnavailableException) {
            throw InvalidInputException::field('_', 'error.' . HubSpotTokenOpener::KEY_UNAVAILABLE);
        }
        if (!$token instanceof HubSpotToken) {
            throw InvalidInputException::field('_', 'error.hubspot_token_invalid');
        }

        return [$connection, $token];
    }

    /**
     * A HubSpot import of the tenant in preview.
     */
    private function import(TenantRef $tenant, string $id): Import
    {
        $import = RecordId::isValid($id) ? $this->support->store->import($tenant, $id) : null;
        if (!$import instanceof Import || $import->getFormat() !== ImportFormat::HubSpot) {
            throw new NotAvailableException();
        }
        if ($import->getStatus() !== ImportStatus::Preview) {
            throw new ConflictException(ConflictException::IMPORT_RUNNING, $id);
        }

        return $import;
    }

    private function scope(Import $import): HubSpotImportScope
    {
        return HubSpotImportScope::fromColumns($import->getColumns());
    }

    private function mapping(Import $import): HubSpotMapping
    {
        return HubSpotMapping::fromFlat($import->getMapping());
    }

    /**
     * The active custom field keys per object of the scope.
     *
     * @return array<string, list<string>>
     */
    private function customKeys(TenantRef $tenant, HubSpotImportScope $scope): array
    {
        $keys = [];
        foreach ($scope->objects as $object) {
            $keys[$object->value] = array_values(array_map(static fn (FieldDefinition $f): string => $f->getFieldKey(), array_filter($this->support->store->fields($tenant, $object->subjectType(), false), static fn (FieldDefinition $f): bool => !$f->isArchived())));
        }

        return $keys;
    }
}
