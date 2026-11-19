<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiAffiliationSpec;
use Nowo\CrmKitBundle\Api\Input\CrmApiNoteDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiTagRef;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiPresenter;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Application\NoteService;
use Nowo\CrmKitBundle\Application\RecordService;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Application\TagService;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TagName;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function in_array;
use function is_string;

/**
 * Affiliations, notes and tags through the API (0.3.0, design §6.2): `crm_affiliation_set` / `_remove`,
 * `crm_note_add` (never pinned, QA-8), `crm_tag_add` / `_remove` of an existing tag (by id or exact name). Stateless.
 */
final readonly class CrmApiRelationOperations
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmApiGuard $guard,
        private CrmApiWriter $writer,
        private CrmApiRecords $records,
        private RecordService $service,
        private NoteService $notes,
        private TagService $tags,
        private RecordViews $views,
        private WriteSupport $support,
    ) {
    }

    /**
     * `crm_affiliation_set`: creates or updates the pair; a null part keeps the stored value. Needs `edit` on the
     * contact, and on the company when its primary contact changes.
     */
    public function setAffiliation(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, CrmApiAffiliationSpec $spec): CrmApiWritten
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::AffiliationSet);

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::AffiliationSet,
            $spec->canonical(),
            200,
            static fn (mixed $id): array => ['affiliation' => is_string($id) ? $id : ''],
            fn (CrmApiCall $bound): CrmApiWritten => new CrmApiWritten($this->affiliationOf($call, $spec, $this->apply($bound, $spec)), false, true),
            function (CrmApiReplay $r) use ($call, $spec): CrmApiWritten {
                $this->records->probe($call, SubjectType::Contact, $spec->contactId);

                return new CrmApiWritten($this->affiliationOf($call, $spec, $r->ids['affiliation'] ?? ''), true, true, $r->status);
            },
        );
    }

    /**
     * Applies an affiliation for a call whose checks are this method's (also the follow-up of a contact create); the
     * affiliation id.
     *
     * @internal
     */
    public function apply(CrmApiCall $call, CrmApiAffiliationSpec $spec): string
    {
        $contact = $this->records->probe($call, SubjectType::Contact, $spec->contactId);
        $this->records->probe($call, SubjectType::Company, $spec->link->companyId);
        $call->require(CrmApiCapability::Edit, $contact);
        $existing = $this->pair($call, $spec->contactId->value, $spec->link->companyId->value);
        $link     = $spec->link;

        return CrmApiErrors::run(fn (): string => $this->service->as($call->caller)->affiliate(
            $spec->contactId->value,
            $link->companyId->value,
            $link->role ?? $existing?->getRole(),
            $link->primary ?? $existing?->isPrimary() ?? false,
            $link->primaryContact,
            $link->purposes,
        ));
    }

    /**
     * `crm_affiliation_remove`: an absent pair answers `removed: false`; removing the company's primary contact needs
     * `edit` on the company.
     */
    public function removeAffiliation(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $contactId, RecordId $companyId): CrmApiWritten
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::AffiliationRemove);

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::AffiliationRemove,
            ['contact_id' => $contactId->value, 'company_id' => $companyId->value],
            200,
            static fn (): array => ['removed' => '1'],
            function (CrmApiCall $bound) use ($contactId, $companyId): CrmApiWritten {
                $contact = $this->records->probe($bound, SubjectType::Contact, $contactId);
                $this->records->probe($bound, SubjectType::Company, $companyId);
                $bound->require(CrmApiCapability::Edit, $contact);
                $pair = $this->pair($bound, $contactId->value, $companyId->value);
                if (!$pair instanceof Affiliation) {
                    return new CrmApiWritten(new CrmApiItem('removed', ['removed' => false]), false, false);
                }
                CrmApiErrors::run(fn () => $this->service->as($bound->caller)->unaffiliate($pair->getId()));

                return new CrmApiWritten(new CrmApiItem('removed', ['removed' => true]), false, true);
            },
            static fn (CrmApiReplay $r): CrmApiWritten => new CrmApiWritten(new CrmApiItem('removed', ['removed' => true]), true, true, $r->status),
        );
    }

    /**
     * `crm_note_add`: plain text, never pinned.
     */
    public function addNote(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, SubjectType $type, RecordId $id, CrmApiNoteDraft $note): CrmApiWritten
    {
        self::record($type);
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::NoteAdd);

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::NoteAdd,
            ['type' => $type->value, 'id' => $id->value] + $note->canonical(),
            201,
            static fn (mixed $noteId): array => ['note' => is_string($noteId) ? $noteId : '', 'subject' => $id->value],
            function (CrmApiCall $bound) use ($call, $type, $id, $note): CrmApiWritten {
                $subject = $this->records->probe($bound, $type, $id);
                $bound->require(CrmApiCapability::Edit, $subject);
                $noteId = CrmApiErrors::run(fn (): string => $this->notes->as($bound->caller)->add($type, $id->value, $note->body, false));

                return new CrmApiWritten($this->note($call, $noteId), false, true, 201);
            },
            function (CrmApiReplay $r) use ($call, $type, $id): CrmApiWritten {
                $this->records->probe($call, $type, $id);

                return new CrmApiWritten($this->note($call, $r->ids['note'] ?? ''), true, true, $r->status);
            },
        );
    }

    /**
     * `crm_tag_add`: an existing tag of the tenant (unknown ⇒ 422 `INPUT_004` on `tag`); already there ⇒
     * `changed: false`.
     */
    public function addTag(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, SubjectType $type, RecordId $id, CrmApiTagRef $tag): CrmApiWritten
    {
        return $this->tag(CrmApiOperation::TagAdd, $principal, $tenant, $key, $type, $id, $tag, true);
    }

    /**
     * `crm_tag_remove`: absent ⇒ `changed: false`.
     */
    public function removeTag(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, SubjectType $type, RecordId $id, CrmApiTagRef $tag): CrmApiWritten
    {
        return $this->tag(CrmApiOperation::TagRemove, $principal, $tenant, $key, $type, $id, $tag, false);
    }

    private function tag(CrmApiOperation $operation, CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, SubjectType $type, RecordId $id, CrmApiTagRef $tag, bool $add): CrmApiWritten
    {
        self::record($type);
        $call = $this->guard->call($principal, $tenant, $operation);

        return $this->writer->write(
            $call,
            $key,
            $operation,
            ['type' => $type->value, 'id' => $id->value] + $tag->canonical(),
            200,
            static fn (mixed $changed): array => ['changed' => $changed === true ? '1' : '0'],
            function (CrmApiCall $bound) use ($type, $id, $tag, $add): CrmApiWritten {
                $subject = $this->records->probe($bound, $type, $id);
                $bound->require(CrmApiCapability::Edit, $subject);
                $tagId   = $this->tagId($bound, $tag);
                $changed = CrmApiErrors::run(fn (): bool => $this->tags->as($bound->caller)->toggle($type, $id->value, $tagId, $add));

                return new CrmApiWritten(new CrmApiItem('tagging', ['type' => $type->value, 'id' => $id->value, 'tag_id' => $tagId, 'changed' => $changed]), false, $changed);
            },
            function (CrmApiReplay $r) use ($call, $type, $id, $tag): CrmApiWritten {
                $this->records->probe($call, $type, $id);
                $changed = ($r->ids['changed'] ?? '0') === '1';

                return new CrmApiWritten(new CrmApiItem('tagging', ['type' => $type->value, 'id' => $id->value, 'tag_id' => $this->tagId($call, $tag), 'changed' => $changed]), true, $changed, $r->status);
            },
        );
    }

    /**
     * The id of an existing tag of the tenant.
     */
    private function tagId(CrmApiCall $call, CrmApiTagRef $ref): string
    {
        $support = $this->support->as($call->caller);
        $tags    = $support->read(static fn (): array => $support->store->tags($call->context->tenant));
        $key     = $ref->name === null ? null : (new TagName($ref->name))->key();
        foreach ($tags as $tag) {
            if ($tag instanceof Tag && (($ref->id !== null && $tag->getId() === $ref->id->value) || ($key !== null && $tag->getNameKey() === $key))) {
                return $tag->getId();
            }
        }

        throw CrmApiRefusal::refused(['tag' => 'error.tag_unknown']);
    }

    /**
     * The stored affiliation of a pair, or null.
     */
    private function pair(CrmApiCall $call, string $contactId, string $companyId): ?Affiliation
    {
        $support = $this->support->as($call->caller);
        foreach ($support->read(static fn (): array => $support->store->affiliations($call->context->tenant, SubjectType::Contact, $contactId)) as $affiliation) {
            if ($affiliation->getCompanyId() === $companyId) {
                return $affiliation;
            }
        }

        return null;
    }

    /**
     * The answer of an affiliation write, seen from the contact.
     */
    private function affiliationOf(CrmApiCall $call, CrmApiAffiliationSpec $spec, string $id): CrmApiItem
    {
        $support = $this->support->as($call->caller);
        $views   = $support->read(fn (): array => $this->views->affiliations($call->context->tenant, SubjectType::Contact, $spec->contactId->value, $call->access->visibleOwners($call->context)));
        foreach ($views as $view) {
            if ($view instanceof AffiliationView && $view->id === $id) {
                return CrmApiPresenter::affiliation($view, SubjectType::Contact);
            }
        }

        throw CrmApiRefusal::notFound();
    }

    /**
     * A note of the call's tenant.
     */
    private function note(CrmApiCall $call, string $id): CrmApiItem
    {
        $support = $this->support->as($call->caller);
        $note    = RecordId::isValid($id) ? $support->read(static fn (): ?Note => $support->store->note($call->context->tenant, $id)) : null;
        if (!$note instanceof Note) {
            throw CrmApiRefusal::notFound();
        }
        $author = $note->getAuthorId();

        return CrmApiPresenter::note(new NoteView($note->getId(), $note->getBody(), $note->isPinned(), $author, $this->views->actorName($call->context->tenant, $author), $note->getVersion(), $note->getCreatedAt(), $note->getUpdatedAt()));
    }

    private static function record(SubjectType $type): void
    {
        if (!in_array($type, [SubjectType::Company, SubjectType::Contact], true)) {
            throw CrmApiRefusal::malformed('type');
        }
    }
}
