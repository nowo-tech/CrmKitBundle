<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use InvalidArgumentException;
use JsonException;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

use function array_key_exists;
use function array_search;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The run cursor of a HubSpot import (0.10.0, `crm_import.run_cursor`): the account, the stage, HubSpot's `after`
 * token and page number, the owner map (HubSpot owner id ⇒ actor id), the lists and their member kinds, the records
 * read per object and the skipped associations. Ids and counts only, never a value from a record. Advanced in the same
 * unit that settles the page, compared before (compare-and-set under the import row's lock).
 *
 * @internal
 */
final readonly class HubSpotRunState
{
    /** The stage that reads the owners. */
    public const OWNERS = 'owners';

    /** The stage that tags the list members. */
    public const LISTS = 'lists';

    /** Nothing left to read. */
    public const DONE = 'done';

    /**
     * @param list<string> $stages the stages of the run, in order
     * @param array<string, string> $owners HubSpot owner id => actor id
     * @param list<array{id: string, object: string}> $lists
     * @param array<string, int> $seen object => records read
     */
    public function __construct(
        public string $account,
        public array $stages,
        public string $stage,
        public ?string $after = null,
        public int $page = 0,
        public array $owners = [],
        public array $lists = [],
        public int $list = 0,
        public array $seen = [],
        public int $skipped = 0,
        public int $tagged = 0,
    ) {
        if (!HubSpotAccountId::isValid($account) || ($stage !== self::DONE && !in_array($stage, $stages, true)) || ($after !== null && !HubSpotCursor::isValid($after))) {
            throw new InvalidArgumentException('Not a HubSpot run cursor.');
        }
    }

    /**
     * The first cursor of an import of the scope.
     *
     * @param array<string, HubSpotObject|null> $listObjects list id => member kind
     */
    public static function start(HubSpotAccountId $account, HubSpotImportScope $scope, array $listObjects): self
    {
        $stages = [self::OWNERS];
        foreach ($scope->objects as $object) {
            $stages[] = $object->value;
        }
        $lists = [];
        foreach ($scope->lists as $list) {
            $object = $listObjects[$list->value] ?? null;
            if ($object instanceof HubSpotObject) {
                $lists[] = ['id' => $list->value, 'object' => $object->value];
            }
        }
        if ($lists !== []) {
            $stages[] = self::LISTS;
        }

        return new self($account->value, $stages, self::OWNERS, lists: $lists);
    }

    /**
     * The cursor stored on an import.
     *
     * @param array<array-key, mixed>|null $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            throw new InvalidArgumentException('Not a HubSpot run cursor.');
        }
        $stages = self::strings($data['stages'] ?? null);
        $owners = [];
        foreach (is_array($data['owners'] ?? null) ? $data['owners'] : [] as $owner => $actor) {
            if (is_string($actor)) {
                $owners[(string) $owner] = $actor;
            }
        }
        $lists = [];
        foreach (is_array($data['lists'] ?? null) ? $data['lists'] : [] as $list) {
            if (is_array($list) && is_string($list['id'] ?? null) && HubSpotObjectId::isValid($list['id']) && is_string($list['object'] ?? null) && HubSpotObject::tryFrom($list['object']) instanceof HubSpotObject) {
                $lists[] = ['id' => $list['id'], 'object' => $list['object']];
            }
        }
        $seen = [];
        foreach (is_array($data['seen'] ?? null) ? $data['seen'] : [] as $object => $count) {
            if (is_int($count)) {
                $seen[(string) $object] = $count;
            }
        }

        return new self(
            is_string($data['account'] ?? null) ? $data['account'] : '',
            $stages,
            is_string($data['stage'] ?? null) ? $data['stage'] : '',
            is_string($data['after'] ?? null) ? $data['after'] : null,
            is_int($data['page'] ?? null) ? $data['page'] : 0,
            $owners,
            $lists,
            is_int($data['list'] ?? null) ? $data['list'] : 0,
            $seen,
            is_int($data['skipped'] ?? null) ? $data['skipped'] : 0,
            is_int($data['tagged'] ?? null) ? $data['tagged'] : 0,
        );
    }

    /**
     * The cursor as stored.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account' => $this->account,
            'stages'  => $this->stages,
            'stage'   => $this->stage,
            'after'   => $this->after,
            'page'    => $this->page,
            'owners'  => $this->owners,
            'lists'   => $this->lists,
            'list'    => $this->list,
            'seen'    => $this->seen,
            'skipped' => $this->skipped,
            'tagged'  => $this->tagged,
        ];
    }

    /**
     * The canonical JSON (the compare-and-set compares it).
     *
     * @throws JsonException
     */
    public function json(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * The stage's object, or null (owners, lists, done).
     */
    public function object(): ?HubSpotObject
    {
        return HubSpotObject::tryFrom($this->stage);
    }

    /**
     * The `after` token of the stage.
     */
    public function cursor(): ?HubSpotCursor
    {
        return $this->after === null ? null : new HubSpotCursor($this->after);
    }

    /**
     * The list the lists stage reads, or null when every list is done.
     *
     * @return array{id: string, object: string}|null
     */
    public function currentList(): ?array
    {
        return $this->stage === self::LISTS ? ($this->lists[$this->list] ?? null) : null;
    }

    /**
     * After a page: the next page of the stage, or the next stage (or list) when `$next` is null.
     *
     * @param array<string, string> $owners owner ids mapped by this page (owners stage)
     */
    public function advance(?HubSpotCursor $next, int $read = 0, int $skipped = 0, int $tagged = 0, array $owners = []): self
    {
        $seen = $this->seen;
        $obj  = $this->object();
        if ($obj instanceof HubSpotObject) {
            $seen[$obj->value] = ($seen[$obj->value] ?? 0) + $read;
        }
        $stage = $this->stage;
        $list  = $this->list;
        if (!$next instanceof HubSpotCursor) {
            if ($stage === self::LISTS && $list + 1 < count($this->lists)) {
                ++$list;
            } else {
                $at    = array_search($stage, $this->stages, true);
                $stage = is_int($at) && array_key_exists($at + 1, $this->stages) ? $this->stages[$at + 1] : self::DONE;
            }
        }

        return new self($this->account, $this->stages, $stage, $next?->value, $next instanceof HubSpotCursor ? $this->page + 1 : 0, $owners + $this->owners, $this->lists, $list, $seen, $this->skipped + $skipped, $this->tagged + $tagged);
    }

    /**
     * Whether the run read everything.
     */
    public function isDone(): bool
    {
        return $this->stage === self::DONE;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
