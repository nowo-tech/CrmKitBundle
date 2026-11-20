<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\Input\CrmApiAffiliationSpec;
use Nowo\CrmKitBundle\Api\Input\CrmApiCompanyLink;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Input\CrmApiNoteDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiPageRequest;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiSearchQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiSort;
use Nowo\CrmKitBundle\Api\Input\CrmApiTagRef;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Port\CrmApiCallerFactoryInterface;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiList;
use Nowo\CrmKitBundle\Api\Read\CrmApiPage;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Application\HostLinkService;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function json_decode;
use function json_encode;

/**
 * The record operations of the API (0.3.0): reads, keyset paging, duplicate-first creates, updates with a base
 * version, trash, affiliations, notes, tags, idempotency and the audit with the token.
 */
final class ApiRecordsTest extends ApiTestCase
{
    public function testCreateReadUpdateTrashWithIdempotencyAndAudit(): void
    {
        $this->bootApi();
        $p   = $this->principal();
        $t   = $this->acme();
        $key = $this->key('create-1');

        $first = $this->writes()->createCompany($p, $t, $key, CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'Acme Tools', 'website' => 'https://acme.test', 'roles' => ['customer']]));
        self::assertSame([201, false, true], [$first->status, $first->replayed, $first->changed]);
        $created = $first->result;
        self::assertInstanceOf(CrmApiItem::class, $created);
        $record = $created->fact('record');
        self::assertInstanceOf(CrmApiItem::class, $record);
        $id = $record->fact('id');
        self::assertIsString($id);
        self::assertSame(['ana', ['customer'], 'Acme Tools'], [$record->fact('owner_id'), $record->fact('roles'), $record->text['label']]);
        self::assertFalse($created->fact('duplicate'));

        // The same key and input: a replay, nothing written twice, no second audit entry.
        $audit  = count($this->recorder()->audit);
        $replay = $this->writes()->createCompany($p, $t, $key, CrmApiRecordDraft::fromArray(SubjectType::Company, ['website' => 'https://acme.test', 'name' => 'Acme Tools', 'roles' => ['customer']]));
        self::assertSame([201, true], [$replay->status, $replay->replayed]);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));
        self::assertCount($audit, $this->recorder()->audit);
        // The same key with another input.
        $this->refused(CrmApiCode::IDEMP_MISMATCH, fn (): CrmApiWritten => $this->writes()->createCompany($p, $t, $key, CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'Other'])));
        // Another key: the domain matches, the existing company comes back and nothing is created.
        $dup = $this->writes()->createCompany($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'Acme Tools SL', 'website' => 'https://www.acme.test/x']));
        self::assertSame([200, false, false], [$dup->status, $dup->replayed, $dup->changed]);
        self::assertInstanceOf(CrmApiItem::class, $dup->result);
        self::assertSame([true, 'domain'], [$dup->result->fact('duplicate'), $dup->result->fact('duplicate_of')]);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));

        // The audit carries the token; the timeline says "via".
        $entries = array_values(array_filter($this->recorder()->audit, static fn (AuditEntry $e): bool => $e->action === 'crm.company.created'));
        self::assertSame(['api', 'tok-1', 'Agent tok-1'], [$entries[0]->origin?->channel, $entries[0]->origin?->tokenId, $entries[0]->origin?->tokenName]);
        $timeline = $this->reads()->timeline($p, $t, SubjectType::Company, new RecordId($id), new CrmApiPageRequest());
        $first    = $timeline->items[0];
        self::assertInstanceOf(CrmApiItem::class, $first);
        $params = $first->text['params'];
        self::assertIsArray($params);
        self::assertSame(['api', 'Agent tok-1'], [$params['via'] ?? null, $params['token'] ?? null]);

        // Update: the base version is required and checked.
        $this->refused(CrmApiCode::STALE, fn (): CrmApiWritten => $this->writes()->updateCompany($p, $t, $this->key(), new RecordId($id), new RecordVersion(9), CrmApiRecordChanges::fromArray(SubjectType::Company, ['city' => 'Madrid'])));
        $refusal = $this->refused(CrmApiCode::STALE, fn (): CrmApiWritten => $this->writes()->updateCompany($p, $t, $this->key(), new RecordId($id), new RecordVersion(7), new CrmApiRecordChanges(SubjectType::Company, ['city' => 'x'])));
        self::assertSame(['current_version' => 1], $refusal->extra);
        $updated = $this->writes()->updateCompany($p, $t, $this->key('upd'), new RecordId($id), new RecordVersion(1), CrmApiRecordChanges::fromArray(SubjectType::Company, ['city' => 'Madrid', 'owner' => 'none']));
        self::assertTrue($updated->changed);
        self::assertInstanceOf(CrmApiItem::class, $updated->result);
        $values = $updated->result->text['values'];
        self::assertIsArray($values);
        self::assertSame([2, null, 'Madrid'], [$updated->result->fact('version'), $updated->result->fact('owner_id'), $values['city'] ?? null]);
        $same = $this->writes()->updateCompany($p, $t, $this->key(), new RecordId($id), new RecordVersion(2), CrmApiRecordChanges::fromArray(SubjectType::Company, ['city' => 'Madrid']));
        self::assertFalse($same->changed);
        self::assertTrue($this->writes()->updateCompany($p, $t, $this->key('upd'), new RecordId($id), new RecordVersion(1), CrmApiRecordChanges::fromArray(SubjectType::Company, ['city' => 'Madrid', 'owner' => 'none']))->replayed);
        $this->refused(CrmApiCode::INPUT_REFUSED, fn (): CrmApiWritten => $this->writes()->updateCompany($p, $t, $this->key(), new RecordId($id), new RecordVersion(2), CrmApiRecordChanges::fromArray(SubjectType::Company, ['name' => ''])));
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiRecordChanges => CrmApiRecordChanges::fromArray(SubjectType::Company, ['nope' => 1]));
        $this->refused(CrmApiCode::INPUT_MALFORMED, fn (): CrmApiWritten => $this->writes()->updateContact($p, $t, $this->key(), new RecordId($id), new RecordVersion(2), new CrmApiRecordChanges(SubjectType::Company)));

        // Trash: destructive; afterwards the record is out of reach, and the same key replays.
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->writes()->trash($this->principal(destructive: false), $t, $this->key(), SubjectType::Company, new RecordId($id), null));
        $trash = $this->writes()->trash($p, $t, $this->key('trash'), SubjectType::Company, new RecordId($id), null);
        self::assertEquals(['type' => 'company', 'id' => $id, 'status' => 'trashed', 'text' => (object) [], 'replayed' => false, 'changed' => true], (array) json_decode((string) json_encode($trash), false));
        self::assertTrue($this->writes()->trash($p, $t, $this->key('trash'), SubjectType::Company, new RecordId($id), null)->replayed);
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiWritten => $this->writes()->trash($p, $t, $this->key(), SubjectType::Company, new RecordId($id), null));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $this->reads()->company($p, $t, new RecordId($id)));
        $this->refused(CrmApiCode::INPUT_MALFORMED, fn (): CrmApiWritten => $this->writes()->trash($p, $t, $this->key(), SubjectType::Deal, new RecordId($id), null));
        self::assertContains('crm.company.trashed', $this->recorder()->actions());
    }

    public function testContactDuplicatesHintsAndAffiliationOnCreate(): void
    {
        $this->bootApi();
        $p    = $this->principal();
        $t    = $this->acme();
        $acme = $this->company('Acme');
        $made = $this->writes()->createContact($p, $t, $this->key('c1'), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'L', 'email' => 'Ada@X.test', 'company' => ['company_id' => $acme, 'role' => 'CEO', 'primary' => true]]));
        self::assertInstanceOf(CrmApiItem::class, $made->result);
        $record = $made->result->fact('record');
        self::assertInstanceOf(CrmApiItem::class, $record);
        $affiliations = $record->fact('affiliations');
        self::assertIsArray($affiliations);
        self::assertCount(1, $affiliations);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));
        // The replay repeats the affiliation (set semantics): still one.
        self::assertTrue($this->writes()->createContact($p, $t, $this->key('c1'), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'L', 'email' => 'Ada@X.test', 'company' => ['company_id' => $acme, 'role' => 'CEO', 'primary' => true]]))->replayed);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));

        $dup = $this->writes()->createContact($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['last_name' => 'Other', 'email' => 'ada@x.test']));
        self::assertInstanceOf(CrmApiItem::class, $dup->result);
        self::assertSame([true, 'email'], [$dup->result->fact('duplicate'), $dup->result->fact('duplicate_of')]);
        // A name match is a hint only.
        $twin = $this->writes()->createContact($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'L']));
        self::assertInstanceOf(CrmApiItem::class, $twin->result);
        self::assertCount(1, (array) $twin->result->fact('possible_duplicates'));

        // A hidden duplicate: CRM_006 without an id; a trashed visible one: CRM_003 with its id.
        $hidden                = $this->contact('Hid', 'Den', 'hid@x.test');
        $this->ctx()->denied[] = 'view:' . $hidden;
        $refusal               = $this->refused(CrmApiCode::HIDDEN_DUPLICATE, fn (): CrmApiWritten => $this->writes()->createContact($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['last_name' => 'X', 'email' => 'hid@x.test'])));
        self::assertSame([], $refusal->extra);
        $gone = $this->contact('Gone', 'G', 'gone@x.test');
        $this->records()->trash(SubjectType::Contact, $gone, 1);
        self::assertSame(['record_id' => $gone], $this->refused(CrmApiCode::TRASHED, fn (): CrmApiWritten => $this->writes()->createContact($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['last_name' => 'X', 'email' => 'gone@x.test'])))->extra);

        // Company rules: tax id first, a name only without tax id and website.
        $this->writes()->createCompany($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'Beta', 'tax_id' => 'B-123']));
        $byTax = $this->writes()->createCompany($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'Beta 2', 'tax_id' => 'b123']));
        self::assertInstanceOf(CrmApiItem::class, $byTax->result);
        self::assertSame('tax_id', $byTax->result->fact('duplicate_of'));
        $byName = $this->writes()->createCompany($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'acme']));
        self::assertInstanceOf(CrmApiItem::class, $byName->result);
        self::assertSame('name', $byName->result->fact('duplicate_of'));
        $namesake = $this->writes()->createCompany($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Company, ['name' => 'acme', 'tax_id' => 'Z9']));
        self::assertTrue($namesake->changed);
        $this->refused(CrmApiCode::INPUT_MALFORMED, fn (): CrmApiWritten => $this->writes()->createCompany($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['last_name' => 'x'])));
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiRecordDraft => CrmApiRecordDraft::fromArray(SubjectType::Company, ['company' => []]));
        $this->ctx()->limits['contacts'] = 1;
        $this->refused(CrmApiCode::LIMIT, fn (): CrmApiWritten => $this->writes()->createContact($p, $t, $this->key(), CrmApiRecordDraft::fromArray(SubjectType::Contact, ['last_name' => 'Limit'])));
    }

    public function testReadsPagesAndTombstones(): void
    {
        $this->bootApi();
        $p = $this->principal();
        $t = $this->acme();
        foreach (['Delta', 'alpha', 'Charlie', 'bravo'] as $name) {
            $this->company($name);
        }
        $contact = $this->contact('Ann', 'Zed', 'ann@x.test');
        $names   = [];
        $cursor  = null;
        do {
            $page = $this->reads()->search($p, $t, new CrmApiSearchQuery(page: new CrmApiPageRequest($cursor === null ? null : new CrmApiCursor($cursor), 2)));
            foreach ($page->items as $item) {
                self::assertInstanceOf(CrmApiItem::class, $item);
                $names[] = $item->text['label'];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        self::assertSame(['alpha', 'bravo', 'Charlie', 'Delta', 'Ann Zed'], $names);
        $updated = $this->reads()->search($p, $t, new CrmApiSearchQuery([SubjectType::Company], sort: CrmApiSort::Updated, page: new CrmApiPageRequest(null, 3)));
        self::assertCount(3, $updated->items);
        self::assertNotNull($updated->nextCursor);
        // A cursor for other filters, another operation or tampered: INPUT_003.
        $this->refused(CrmApiCode::INPUT_CURSOR, fn (): CrmApiPage => $this->reads()->search($p, $t, new CrmApiSearchQuery([SubjectType::Contact], page: new CrmApiPageRequest(new CrmApiCursor($updated->nextCursor)))));
        $this->refused(CrmApiCode::INPUT_CURSOR, fn (): CrmApiPage => $this->reads()->notes($p, $t, SubjectType::Contact, new RecordId($contact), new CrmApiPageRequest(new CrmApiCursor($updated->nextCursor))));
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiSearchQuery => new CrmApiSearchQuery(segment: 'vip'));
        $this->refused(CrmApiCode::INPUT_MALFORMED, static fn (): CrmApiSearchQuery => new CrmApiSearchQuery([SubjectType::Deal]));
        $text = $this->reads()->search($p, $t, new CrmApiSearchQuery(text: 'ALP'));
        self::assertCount(1, $text->items);
        self::assertCount(0, $this->reads()->search($p, $t, new CrmApiSearchQuery(text: '%'))->items);
        self::assertCount(0, $this->reads()->search($p, $t, new CrmApiSearchQuery(role: new RoleKey('supplier')))->items);

        // Contact read, notes keyset (pinned first), consents, links, tags, affiliations, identity.
        $notes = $this->service(NoteServiceInterface::class);
        $notes->add(SubjectType::Contact, $contact, 'first');
        $notes->add(SubjectType::Contact, $contact, 'pinned one', true);
        $notes->add(SubjectType::Contact, $contact, 'third');
        $page1 = $this->reads()->notes($p, $t, SubjectType::Contact, new RecordId($contact), new CrmApiPageRequest(null, 2));
        $page2 = $this->reads()->notes($p, $t, SubjectType::Contact, new RecordId($contact), new CrmApiPageRequest(new CrmApiCursor((string) $page1->nextCursor), 2));
        self::assertSame(['pinned one', 'third', 'first'], array_map(static fn (CrmApiItem $i): mixed => $i->text['body'], [...$this->items($page1), ...$this->items($page2)]));
        self::assertNull($page2->nextCursor);
        $this->service(ConsentServiceInterface::class)->record($contact, 'newsletter', true, ConsentSource::Form, 'form #1');
        $consents = $this->reads()->consents($p, $t, new RecordId($contact), true);
        self::assertCount(3, (array) $consents->fact('purposes'));
        self::assertCount(1, (array) $consents->fact('history'));
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiItem => $this->reads()->consents($this->principal(consents: false), $t, new RecordId($contact)));
        $this->service(HostLinkServiceInterface::class)->link(SubjectType::Contact, $contact, new HostObjectRef('project', 'p1'));
        self::assertCount(1, $this->reads()->links($p, $t, SubjectType::Contact, new RecordId($contact))->items);
        $tag = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        self::assertCount(1, $this->reads()->tags($p, $t)->items);
        $tagged = $this->relations()->addTag($p, $t, $this->key('tag'), SubjectType::Contact, new RecordId($contact), CrmApiTagRef::byName('vip'));
        self::assertTrue($tagged->changed);
        self::assertTrue($this->relations()->addTag($p, $t, $this->key('tag'), SubjectType::Contact, new RecordId($contact), CrmApiTagRef::byName('vip'))->replayed);
        self::assertFalse($this->relations()->addTag($p, $t, $this->key(), SubjectType::Contact, new RecordId($contact), CrmApiTagRef::byId(new RecordId($tag)))->changed);
        $this->refused(CrmApiCode::INPUT_REFUSED, fn (): CrmApiWritten => $this->relations()->addTag($p, $t, $this->key(), SubjectType::Contact, new RecordId($contact), CrmApiTagRef::byName('nope')));
        self::assertTrue($this->relations()->removeTag($p, $t, $this->key(), SubjectType::Contact, new RecordId($contact), CrmApiTagRef::byId(new RecordId($tag)))->changed);
        self::assertFalse($this->relations()->removeTag($p, $t, $this->key(), SubjectType::Contact, new RecordId($contact), CrmApiTagRef::byId(new RecordId($tag)))->changed);
        $this->refused(CrmApiCode::INPUT_MALFORMED, fn (): CrmApiWritten => $this->relations()->addTag($p, $t, $this->key(), SubjectType::Deal, new RecordId($contact), CrmApiTagRef::byName('x')));

        $acme = $this->company('Acme');
        $set  = $this->relations()->setAffiliation($p, $t, $this->key('aff'), new CrmApiAffiliationSpec(new RecordId($contact), new CrmApiCompanyLink(new RecordId($acme), 'CTO', true, true, ['billing'])));
        self::assertInstanceOf(CrmApiItem::class, $set->result);
        self::assertSame([$acme, true, true, ['billing'], 'CTO'], [$set->result->fact('other_id'), $set->result->fact('primary'), $set->result->fact('primary_contact'), $set->result->fact('purposes'), $set->result->text['role']]);
        self::assertTrue($this->relations()->setAffiliation($p, $t, $this->key('aff'), new CrmApiAffiliationSpec(new RecordId($contact), new CrmApiCompanyLink(new RecordId($acme), 'CTO', true, true, ['billing'])))->replayed);
        $kept = $this->relations()->setAffiliation($p, $t, $this->key(), CrmApiAffiliationSpec::fromArray(['contact_id' => $contact, 'company_id' => $acme]));
        self::assertInstanceOf(CrmApiItem::class, $kept->result);
        self::assertSame('CTO', $kept->result->text['role'], 'a null part keeps the stored value');
        self::assertCount(1, $this->reads()->affiliations($p, $t, SubjectType::Company, new RecordId($acme), new CrmApiPageRequest())->items);
        $read = $this->reads()->contact($p, $t, new RecordId($contact));
        self::assertSame([false, null], [$read->fact('amounts_hidden'), $read->text['owner']]);
        self::assertIsArray($read->fact('amounts'));
        self::assertTrue($this->relations()->removeAffiliation($p, $t, $this->key('rm'), new RecordId($contact), new RecordId($acme))->changed);
        self::assertTrue($this->relations()->removeAffiliation($p, $t, $this->key('rm'), new RecordId($contact), new RecordId($acme))->replayed);
        self::assertFalse($this->relations()->removeAffiliation($p, $t, $this->key(), new RecordId($contact), new RecordId($acme))->changed);

        $note = $this->relations()->addNote($p, $t, $this->key('n'), SubjectType::Contact, new RecordId($contact), CrmApiNoteDraft::fromArray(['body' => ' Hello ']));
        self::assertInstanceOf(CrmApiItem::class, $note->result);
        self::assertSame([201, 'Hello', false], [$note->status, $note->result->text['body'], $note->result->fact('pinned')]);
        self::assertTrue($this->relations()->addNote($p, $t, $this->key('n'), SubjectType::Contact, new RecordId($contact), new CrmApiNoteDraft(' Hello '))->replayed);
        $this->refused(CrmApiCode::INPUT_REFUSED, fn (): CrmApiWritten => $this->relations()->addNote($p, $t, $this->key(), SubjectType::Contact, new RecordId($contact), new CrmApiNoteDraft('  ')));
        $this->refused(CrmApiCode::INPUT_MALFORMED, fn (): CrmApiWritten => $this->relations()->addNote($p, $t, $this->key(), SubjectType::Deal, new RecordId($contact), new CrmApiNoteDraft('x')));

        $identity = $this->reads()->identity($p, $t);
        self::assertSame(['ana', 'acme'], [$identity->fact('actor_id'), $identity->fact('tenant')]);
        self::assertContains('deal.create', (array) $identity->fact('operations'));

        // A merged record answers its tombstone on reads and CRM_002 on writes.
        $other = $this->company('Acme 2');
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Company, $acme, $other, [], $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$acme]), 1);
        $tomb = $this->reads()->company($p, $t, new RecordId($other));
        self::assertSame(['merged', $acme], [$tomb->fact('status'), $tomb->fact('merged_into_id')]);
        self::assertSame(['merged_into_id' => $acme], $this->refused(CrmApiCode::MERGED, fn (): CrmApiWritten => $this->relations()->addNote($p, $t, $this->key(), SubjectType::Company, new RecordId($other), new CrmApiNoteDraft('x')))->extra);
        $timeline = $this->reads()->timeline($p, $t, SubjectType::Contact, new RecordId($contact), new CrmApiPageRequest(null, 2));
        self::assertNotNull($timeline->nextCursor);
        self::assertCount(2, $this->reads()->timeline($p, $t, SubjectType::Contact, new RecordId($contact), new CrmApiPageRequest(new CrmApiCursor($timeline->nextCursor), 2))->items);
    }

    public function testTheAccessMatrix(): void
    {
        $this->bootApi(['security' => ['resource_scoping' => true]]);
        $t    = $this->acme();
        $mine = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Mine'], 'ana'));
        $leos = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Leos'], 'leo'));
        $free = $this->company('Free');
        $id   = static fn (string $i): RecordId => new RecordId($i);
        $read = fn (CrmApiPrincipal $p, string $c): CrmApiItem => $this->reads()->company($p, $t, $id($c));

        // Out of reach: another tenant, unknown tenant, feature off, a reach without the tenant.
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $this->reads()->company($this->principal(), new TenantRef('beta'), $id($mine)));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $read($this->principal(tenants: ['beta']), $mine));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $read($this->principal(tenants: []), $mine));
        $this->ctx()->enabled['acme'] = false;
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $read($this->principal(), $mine));
        $this->ctx()->enabled['acme'] = true;
        self::assertSame($mine, $read($this->principal(tenants: ['acme']), $mine)->fact('id'));
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $read($this->principal(), '00000000-0000-4000-8000-000000000000'));

        // Scoping: an edit token of a manager sees what a non-manager sees; a manage token sees everything (QA-1).
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $read($this->principal(CrmApiLevel::Edit), $leos));
        self::assertSame($leos, $read($this->principal(CrmApiLevel::Manage), $leos)->fact('id'));
        self::assertSame($free, $read($this->principal(CrmApiLevel::View), $free)->fact('id'));
        $search = $this->reads()->search($this->principal(CrmApiLevel::Edit), $t, new CrmApiSearchQuery([SubjectType::Company]));
        self::assertSame(['Free', 'Mine'], array_map(static fn (CrmApiItem $i): mixed => $i->text['label'], $this->items($search)));
        self::assertCount(3, $this->reads()->search($this->principal(CrmApiLevel::Manage), $t, new CrmApiSearchQuery([SubjectType::Company]))->items);

        // The grant: a view token may not write; a person may not do what the token allows.
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->relations()->addNote($this->principal(CrmApiLevel::View), $t, $this->key(), SubjectType::Company, $id($mine), new CrmApiNoteDraft('x')));
        $this->refused(CrmApiCode::TOKEN, fn (): CrmApiWritten => $this->writes()->createCompany($this->principal(CrmApiLevel::View), $t, $this->key(), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'X'])));
        $this->ctx()->grants['acme']['ana'] = ['view'];
        $this->refused(CrmApiCode::DENIED, fn (): CrmApiWritten => $this->relations()->addNote($this->principal(), $t, $this->key(), SubjectType::Company, $id($mine), new CrmApiNoteDraft('x')));
        $this->refused(CrmApiCode::DENIED, fn (): CrmApiWritten => $this->writes()->createCompany($this->principal(), $t, $this->key(), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'X'])));
        $this->actAs('ana', 'view', 'edit', 'manage', 'amounts_view');
        // A record listener's deny: view ⇒ 404, edit ⇒ 403; the listener sees the channel and the token.
        $this->ctx()->denied = ['api:edit:' . $mine];
        $this->refused(CrmApiCode::DENIED, fn (): CrmApiWritten => $this->relations()->addNote($this->principal(), $t, $this->key(), SubjectType::Company, $id($mine), new CrmApiNoteDraft('x')));
        self::assertContains('api|tok-1', $this->ctx()->seenChannels);
        self::assertContains('visibility:api|tok-1', $this->ctx()->seenChannels);
        // A bound host-link service (0.3.0) answers as the caller.
        $caller = $this->service(CrmApiCallerFactoryInterface::class)->for($this->principal(), $t);
        self::assertSame([], $this->service(HostLinkService::class)->as($caller)->subjectsLinkedTo(new HostObjectRef('project', 'p1')));
        $this->ctx()->denied = ['view:' . $mine];
        $this->refused(CrmApiCode::NOT_FOUND, fn (): CrmApiItem => $read($this->principal(), $mine));
        $this->ctx()->denied = [];
        // Amounts: hidden without the tick or the person's permission.
        self::assertTrue($read($this->principal(amounts: false), $mine)->fact('amounts_hidden'));
        $this->ctx()->grants['acme']['ana'] = ['view', 'edit', 'manage'];
        self::assertTrue($read($this->principal(), $mine)->fact('amounts_hidden'));
        // An unknown actor of the area checker sees nothing at all.
        $this->refused(CrmApiCode::DENIED, fn (): CrmApiList => $this->reads()->tags($this->principal(actor: 'zoe'), $t));
    }

    /**
     * @return list<CrmApiItem>
     */
    private function items(CrmApiPage $page): array
    {
        $items = [];
        foreach ($page->items as $item) {
            self::assertInstanceOf(CrmApiItem::class, $item);
            $items[] = $item;
        }

        return $items;
    }
}
