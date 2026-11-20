<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;

use function array_map;
use function array_values;
use function mb_strlen;
use function preg_match;

/**
 * A search of companies and contacts (0.3.0, `crm_search`): types (default both), text (≤ 100, untrusted, matched with
 * an escaped `LIKE`), role, tag, owner, lifecycle stage, order and page. `segment` is reserved until 0.9.0: any value
 * is `INPUT_002`.
 */
final readonly class CrmApiSearchQuery
{
    /**
     * @param list<SubjectType> $types
     *
     * @throws CrmApiRefusal `INPUT_002`
     */
    public function __construct(
        public array $types = [SubjectType::Company, SubjectType::Contact],
        public ?string $text = null,
        public ?RoleKey $role = null,
        public ?RecordId $tagId = null,
        public ?ActorRef $ownerId = null,
        public ?CrmApiStageFilter $stage = null,
        public CrmApiSort $sort = CrmApiSort::Name,
        public CrmApiPageRequest $page = new CrmApiPageRequest(),
        ?string $segment = null,
    ) {
        if ($segment !== null) {
            throw CrmApiRefusal::malformed('segment');
        }
        if ($types === [] || array_values($types) !== $types) {
            throw CrmApiRefusal::malformed('types');
        }
        foreach ($types as $type) {
            if ($type === SubjectType::Deal) {
                throw CrmApiRefusal::malformed('types');
            }
        }
        if ($text !== null && (preg_match('//u', $text) !== 1 || mb_strlen($text) > 100)) {
            throw CrmApiRefusal::malformed('q');
        }
    }

    /**
     * The filters, without the page (the cursor's filter hash).
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'types' => array_map(static fn (SubjectType $t): string => $t->value, $this->types),
            'q'     => $this->text,
            'role'  => $this->role?->value,
            'tag'   => $this->tagId?->value,
            'owner' => $this->ownerId?->value,
            'stage' => $this->stage?->canonical(),
            'sort'  => $this->sort->value,
        ];
    }
}
