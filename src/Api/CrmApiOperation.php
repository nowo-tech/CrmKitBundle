<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

use function in_array;

/**
 * The operation catalogue of the API (0.3.0, design §6.1), 1:1 with the MCP tools: each case carries its tool name,
 * capability, kind, idempotency, base-version rule, the grant ticks it may need, the refusal codes it can answer and
 * the release that brought it. `tests/Contract/crm-api-v1.json` is generated from it.
 */
enum CrmApiOperation: string
{
    case Search            = 'search';
    case CompanyGet        = 'company.get';
    case ContactGet        = 'contact.get';
    case Timeline          = 'timeline';
    case NotesList         = 'notes.list';
    case ConsentsGet       = 'consents.get';
    case DealSearch        = 'deal.search';
    case DealGet           = 'deal.get';
    case FunnelStats       = 'funnel.stats';
    case CompanyCreate     = 'company.create';
    case ContactCreate     = 'contact.create';
    case CompanyUpdate     = 'company.update';
    case ContactUpdate     = 'contact.update';
    case AffiliationSet    = 'affiliation.set';
    case AffiliationRemove = 'affiliation.remove';
    case NoteAdd           = 'note.add';
    case TagAdd            = 'tag.add';
    case TagRemove         = 'tag.remove';
    case NextActionSet     = 'next_action.set';
    case DealCreate        = 'deal.create';
    case DealUpdate        = 'deal.update';
    case DealMoveStage     = 'deal.move_stage';
    case RecordTrash       = 'record.trash';
    case DealTrash         = 'deal.trash';
    case TagsList          = 'tags.list';
    case FunnelsList       = 'funnels.list';
    case LinksList         = 'links.list';
    case AffiliationsList  = 'affiliations.list';
    case Identity          = 'identity';

    public const KIND_READ = 'read';

    public const KIND_ADDITIVE = 'additive';

    public const KIND_DESTRUCTIVE = 'destructive';

    public const VERSION_REQUIRED = 'required';

    public const VERSION_OPTIONAL = 'optional';

    public const VERSION_NONE = 'none';

    /**
     * The MCP tool name, or null for an operation only the JSON API exposes.
     */
    public function toolName(): ?string
    {
        return match ($this) {
            self::Search                                            => 'crm_search',
            self::CompanyGet                                        => 'crm_company_get',
            self::ContactGet                                        => 'crm_contact_get',
            self::Timeline                                          => 'crm_timeline',
            self::NotesList                                         => 'crm_notes_list',
            self::ConsentsGet                                       => 'crm_consents_get',
            self::DealSearch                                        => 'crm_deal_search',
            self::DealGet                                           => 'crm_deal_get',
            self::FunnelStats                                       => 'crm_funnel_stats',
            self::CompanyCreate                                     => 'crm_company_create',
            self::ContactCreate                                     => 'crm_contact_create',
            self::CompanyUpdate                                     => 'crm_company_update',
            self::ContactUpdate                                     => 'crm_contact_update',
            self::AffiliationSet                                    => 'crm_affiliation_set',
            self::AffiliationRemove                                 => 'crm_affiliation_remove',
            self::NoteAdd                                           => 'crm_note_add',
            self::TagAdd                                            => 'crm_tag_add',
            self::TagRemove                                         => 'crm_tag_remove',
            self::NextActionSet                                     => 'crm_next_action_set',
            self::DealCreate                                        => 'crm_deal_create',
            self::DealUpdate                                        => 'crm_deal_update',
            self::DealMoveStage                                     => 'crm_deal_move_stage',
            self::RecordTrash                                       => 'crm_record_trash',
            self::DealTrash                                         => 'crm_deal_trash',
            self::TagsList                                          => 'crm_tag_list',
            self::FunnelsList                                       => 'crm_funnel_list',
            self::LinksList, self::AffiliationsList, self::Identity => null,
        };
    }

    /**
     * What the operation asks of the grant (the amounts and consents parts of a read are listed by
     * {@see self::ticks()}).
     */
    public function capability(): CrmApiCapability
    {
        return match ($this) {
            self::ConsentsGet                                          => CrmApiCapability::ReadConsents,
            self::CompanyCreate, self::ContactCreate, self::DealCreate => CrmApiCapability::Create,
            self::CompanyUpdate, self::ContactUpdate, self::AffiliationSet, self::AffiliationRemove, self::NoteAdd,
            self::TagAdd, self::TagRemove, self::NextActionSet, self::DealUpdate, self::DealMoveStage => CrmApiCapability::Edit,
            self::RecordTrash, self::DealTrash                                                        => CrmApiCapability::Trash,
            default                                                                                   => CrmApiCapability::Read,
        };
    }

    /**
     * The grant ticks the operation may use: `amounts` (values shown or written only with it), `consents`,
     * `destructive`.
     *
     * @return list<string>
     */
    public function ticks(): array
    {
        return match ($this) {
            self::CompanyGet, self::ContactGet, self::DealSearch, self::DealGet, self::FunnelStats, self::DealCreate, self::DealUpdate => ['amounts'],
            self::ConsentsGet                                                                                                          => ['consents'],
            self::RecordTrash, self::DealTrash                                                                                         => ['destructive'],
            default                                                                                                                    => [],
        };
    }

    /**
     * `read`, `additive` or `destructive`.
     */
    public function kind(): string
    {
        return match (true) {
            $this->capability() === CrmApiCapability::Trash => self::KIND_DESTRUCTIVE,
            $this->isWrite()                                => self::KIND_ADDITIVE,
            default                                         => self::KIND_READ,
        };
    }

    /**
     * Whether the operation writes (and needs an idempotency key).
     */
    public function isWrite(): bool
    {
        return in_array($this->capability(), [CrmApiCapability::Create, CrmApiCapability::Edit, CrmApiCapability::Trash], true);
    }

    /**
     * Whether repeating the same call has the same effect (every write is, by its key; reads are).
     */
    public function idempotent(): bool
    {
        return true;
    }

    /**
     * `required`, `optional` or `none`: the base version (HTTP `If-Match`, MCP `base_version`).
     */
    public function needsBaseVersion(): string
    {
        return match ($this) {
            self::CompanyUpdate, self::ContactUpdate, self::DealUpdate => self::VERSION_REQUIRED,
            self::RecordTrash, self::DealTrash, self::DealMoveStage    => self::VERSION_OPTIONAL,
            default                                                    => self::VERSION_NONE,
        };
    }

    /**
     * Whether the operation belongs to the funnels (unavailable while `pipeline.enabled` is false).
     */
    public function pipeline(): bool
    {
        return in_array($this, [self::DealSearch, self::DealGet, self::FunnelStats, self::DealCreate, self::DealUpdate, self::DealMoveStage, self::DealTrash, self::FunnelsList], true);
    }

    /**
     * Whether the operation reads a keyset-paged list.
     */
    public function paged(): bool
    {
        return in_array($this, [self::Search, self::Timeline, self::NotesList, self::DealSearch, self::AffiliationsList], true);
    }

    /**
     * The release that brought the operation.
     */
    public function since(): string
    {
        return '0.3.0';
    }

    /**
     * The refusal codes the operation can answer.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [CrmApiCode::NOT_FOUND, CrmApiCode::TOKEN, CrmApiCode::DENIED, CrmApiCode::INPUT_MALFORMED, CrmApiCode::SERVER];
        if ($this->paged()) {
            $codes[] = CrmApiCode::INPUT_CURSOR;
        }
        if ($this->isWrite()) {
            $codes = [...$codes, CrmApiCode::INPUT_MISSING, CrmApiCode::INPUT_REFUSED, CrmApiCode::IDEMP_MISMATCH, CrmApiCode::IDEMP_INVALID, CrmApiCode::IDEMP_STORE, CrmApiCode::IDEMP_MISSING, CrmApiCode::WRITE_CONFLICT, CrmApiCode::CONFLICT_UNCOMMITTED, CrmApiCode::CONFLICT_COMMITTED];
        }
        if ($this->needsBaseVersion() !== self::VERSION_NONE) {
            $codes[] = CrmApiCode::STALE;
        }
        if ($this->needsBaseVersion() === self::VERSION_REQUIRED) {
            $codes[] = CrmApiCode::VERSION_MISSING;
        }
        $codes = [...$codes, ...match ($this) {
            self::CompanyCreate, self::ContactCreate => [CrmApiCode::LIMIT, CrmApiCode::TRASHED, CrmApiCode::HIDDEN_DUPLICATE, CrmApiCode::MERGED],
            self::DealCreate                         => [CrmApiCode::LIMIT, CrmApiCode::STAGE],
            self::DealMoveStage                      => [CrmApiCode::LOST_REASON, CrmApiCode::STAGE],
            self::DealUpdate                         => [CrmApiCode::STAGE],
            self::NextActionSet                      => [CrmApiCode::MERGED, CrmApiCode::TASK_UNAVAILABLE, CrmApiCode::TASK_REFUSED],
            self::CompanyGet, self::ContactGet, self::Timeline, self::NotesList, self::ConsentsGet, self::LinksList, self::AffiliationsList,
            self::CompanyUpdate, self::ContactUpdate, self::AffiliationSet, self::AffiliationRemove, self::NoteAdd, self::TagAdd, self::TagRemove, self::RecordTrash => [CrmApiCode::MERGED],
            default                                                                                                                                                  => [],
        }];

        return $codes;
    }
}
