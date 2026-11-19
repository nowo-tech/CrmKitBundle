<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RolesChanged;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\ImportRowView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmCatalogueInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function implode;
use function is_scalar;
use function iterator_to_array;

/**
 * Party roles and affiliation purposes (contract section 6.8): many roles per company or contact, set semantics over
 * the catalogue, roles no longer configured kept but hidden, filters, the company's single primary contact, purposes,
 * merges, purge, GDPR, import and export, audit after the commit.
 */
final class RolesAndPurposesTest extends CrmTestCase
{
    public function testRolesOnCompaniesAndContacts(): void
    {
        $this->boot(['roles' => ['catalogue' => ['customer', 'supplier', 'partner', 'distributor']]]);
        $this->admin();
        $catalogue = $this->service(CrmCatalogueInterface::class);
        self::assertSame(['customer', 'supplier', 'partner', 'distributor'], $catalogue->roles());
        self::assertSame(['billing', 'orders', 'technical', 'general'], $catalogue->purposes());

        $acme  = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Acme'], roles: ['supplier', 'customer', 'supplier']));
        $beta  = $this->company('Beta');
        $alice = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Alice', 'last_name' => 'Private'], roles: ['customer']));
        self::assertSame(['customer', 'supplier'], $this->reader()->record(SubjectType::Company, $acme)->roles, 'catalogue order');
        self::assertSame(['customer'], $this->reader()->record(SubjectType::Contact, $alice)->roles);
        self::assertSame([], $this->reader()->record(SubjectType::Company, $beta)->roles);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE kind = 'roles_changed' AND subject_id = ?", [$acme]));

        // Set semantics; an update with roles bumps the version once (the clock moves: versions follow the edit time).
        $this->clock()->sleep(1);
        $this->records()->setRoles(SubjectType::Company, $beta, ['partner', 'distributor']);
        $this->clock()->sleep(1);
        $this->records()->setRoles(SubjectType::Company, $beta, ['partner', 'distributor']);
        self::assertSame(['partner', 'distributor'], $this->reader()->record(SubjectType::Company, $beta)->roles);
        self::assertSame(2, $this->reader()->record(SubjectType::Company, $beta)->version, 'an unchanged set writes nothing');
        $this->clock()->sleep(1);
        $version = $this->records()->update($beta, new RecordInput(SubjectType::Company, [], roles: ['customer']), 2);
        self::assertSame(3, $version);
        self::assertSame(['customer'], $this->reader()->record(SubjectType::Company, $beta)->roles);
        self::assertSame(3, $this->records()->update($beta, new RecordInput(SubjectType::Company, [], roles: ['customer']), 3), 'nothing changed');
        self::assertSame(3, $this->records()->update($beta, new RecordInput(SubjectType::Company, ['city' => null]), 3), 'roles null keep them');
        self::assertSame(['customer'], $this->reader()->record(SubjectType::Company, $beta)->roles);

        $audit = array_map(static fn (AuditEntry $e): string => $e->action . ':' . implode('|', array_map('strval', $e->details)), $this->recorder()->audit);
        self::assertContains('crm.roles.changed:partner,distributor|', $audit, 'codes only');
        self::assertContains('crm.roles.changed:customer|partner,distributor', $audit);
        self::assertNotEmpty(array_filter($this->recorder()->events, static fn (object $e): bool => $e instanceof RolesChanged && $e->removed === ['partner', 'distributor']));

        // A writer that does not lock the record stores the same role at the same moment: one more run, one row.
        $this->ctx()->races['RoleAssignment'] = 1;
        $this->records()->setRoles(SubjectType::Company, $beta, ['customer', 'partner']);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_role_assignment WHERE subject_id = ? AND role_key = 'partner'", [$beta]));
        $this->records()->setRoles(SubjectType::Company, $beta, ['customer']);
        $store = $this->service(CrmStoreInterface::class);
        self::assertSame([[], [], []], [$store->contactIdsByEmailKeys(new TenantRef('acme'), []), $store->affiliatedCompanyIds(new TenantRef('acme'), []), $store->roleAssignments(new TenantRef('acme'), SubjectType::Company, [])]);

        foreach ([fn () => $this->records()->setRoles(SubjectType::Company, $beta, ['Customer']), fn (): string => $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'X'], roles: ['vendor']))] as $call) {
            try {
                $call();
                self::fail('an unknown role was stored');
            } catch (InvalidInputException $e) {
                self::assertSame(['roles' => 'error.role_unknown'], $e->errors);
            }
        }

        // Filters (list, store) by role; an unknown role key in the URL is ignored.
        $reader = $this->reader();
        self::assertSame([$acme, $beta], array_map(static fn (RecordView $v): string => $v->id, $reader->list(new ListQuery(SubjectType::Company, role: 'customer'))->rows));
        self::assertSame([$acme], array_map(static fn (RecordView $v): string => $v->id, $reader->list(new ListQuery(SubjectType::Company, role: 'supplier'))->rows));
        self::assertSame([], $reader->list(new ListQuery(SubjectType::Contact, role: 'supplier'))->rows);
        self::assertSame([$alice], array_map(static fn (RecordView $v): string => $v->id, $reader->list(new ListQuery(SubjectType::Contact, role: 'customer'))->rows));

        // Permissions unchanged: a viewer cannot set roles.
        $this->actAs('vic', 'view');
        try {
            $this->records()->setRoles(SubjectType::Company, $acme, []);
            self::fail('a viewer changed roles');
        } catch (AccessDeniedException) {
        }
        $this->admin();

        // A role removed from the configuration: hidden everywhere, kept in the database, back when configured again.
        $this->kernel->shutdown();
        $this->kernel = $this->bootKernel($this->host()->bundleConfig(['roles' => ['catalogue' => ['customer', 'partner']]] + TestServices::PORTS + ['ui' => ['enabled' => true]]), $this->host()->configure());
        $this->admin();
        self::assertSame(['customer'], $this->reader()->record(SubjectType::Company, $acme)->roles);
        $this->records()->setRoles(SubjectType::Company, $acme, ['partner']);
        self::assertSame(['partner'], $this->reader()->record(SubjectType::Company, $acme)->roles);
        self::assertSame(['partner', 'supplier'], $this->column('SELECT role_key FROM nowo_crm_role_assignment WHERE subject_id = ? ORDER BY role_key', [$acme]), 'the unconfigured role stays');
        $csv = implode('', iterator_to_array($this->service(CrmExporterInterface::class)->csv(new ListQuery(SubjectType::Company, role: 'partner')), false));
        self::assertStringContainsString('"roles"', $csv);
        self::assertStringContainsString('"partner"', $csv);
        self::assertStringNotContainsString('supplier', $csv);
    }

    public function testPrimaryContactAndPurposes(): void
    {
        $this->boot();
        $this->admin();
        $acme  = $this->company('Acme');
        $ada   = $this->contact('Ada', 'A', 'ada@x.test');
        $bob   = $this->contact('Bob', 'B', 'bob@x.test');
        $cyd   = $this->contact('Cyd', 'C');
        $first = $this->records()->affiliate($ada, $acme, 'CFO', true, true, ['billing', 'orders']);
        $this->records()->affiliate($bob, $acme, null, true, null, ['technical']);
        self::assertSame($first, $this->records()->affiliate($ada, $acme, 'CEO', true), 'an existing pair is updated in place');
        $view = $this->reader()->record(SubjectType::Company, $acme);
        self::assertSame([[$ada, 'CEO', true, ['billing', 'orders']], [$bob, null, false, ['technical']]], array_map(static fn (AffiliationView $a): array => [$a->contactId, $a->role, $a->primaryContact, $a->purposes], $view->affiliations));

        // One primary contact per company: setting another clears the first.
        $this->records()->affiliate($bob, $acme, null, true, true);
        self::assertSame([$bob], $this->column('SELECT contact_id FROM nowo_crm_affiliation WHERE is_primary_contact = ?', [true]));
        $this->records()->setPrimaryContact($acme, $ada);
        $this->records()->setPrimaryContact($acme, $ada);
        self::assertSame([$ada], $this->column('SELECT contact_id FROM nowo_crm_affiliation WHERE is_primary_contact = ?', [true]));
        self::assertSame(3, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE kind = 'primary_contact' AND subject_id = ?", [$acme]));
        try {
            $this->records()->setPrimaryContact($acme, $cyd);
            self::fail('a contact without the company became its primary contact');
        } catch (InvalidInputException $e) {
            self::assertSame(['contact' => 'error.not_affiliated'], $e->errors);
        }
        $this->records()->affiliate($ada, $acme, 'CEO', true, false, ['orders']);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE is_primary_contact = ?', [true]));
        $this->records()->setPrimaryContact($acme, $bob);
        $this->records()->setPrimaryContact($acme, null);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE is_primary_contact = ?', [true]));
        $this->records()->setPrimaryContact($acme, $bob);
        try {
            $this->records()->affiliate($ada, $acme, null, false, null, ['marketing']);
            self::fail('an unknown purpose was stored');
        } catch (InvalidInputException $e) {
            self::assertSame(['purposes' => 'error.purpose_unknown'], $e->errors);
        }

        // Filters: purpose, primary contact, both with the company.
        $ids = fn (ListQuery $q): array => array_map(static fn (RecordView $v): string => $v->id, $this->reader()->list($q)->rows);
        self::assertSame([$ada], $ids(new ListQuery(SubjectType::Contact, purpose: 'orders')));
        self::assertSame([$bob], $ids(new ListQuery(SubjectType::Contact, companyId: $acme, purpose: 'technical')));
        self::assertSame([], $ids(new ListQuery(SubjectType::Contact, purpose: 'billing')), 'the billing purpose was replaced');
        self::assertSame([], $ids(new ListQuery(SubjectType::Contact, purpose: 'order_')), 'a LIKE wildcard is literal');
        self::assertSame([$bob], $ids(new ListQuery(SubjectType::Contact, primaryContactOnly: true)));
        self::assertSame([], $ids(new ListQuery(SubjectType::Contact, purpose: 'orders', primaryContactOnly: true)), 'one affiliation matches all filters');

        // Editing the company's primary contact through a contact needs edit on the company.
        $this->actAs('vic', 'view', 'edit:' . $ada);
        try {
            $this->records()->affiliate($ada, $acme, null, false, true);
            self::fail('primary contact set without edit on the company');
        } catch (AccessDeniedException) {
        }
        $this->admin();

        // GDPR: export shows the roles and the affiliation; erasure deletes both.
        $this->records()->setRoles(SubjectType::Contact, $bob, ['customer']);
        $export = $this->service(CrmPersonalDataInterface::class)->export($bob);
        $roles  = $export['roles'] ?? null;
        self::assertIsArray($roles);
        self::assertSame(['customer'], array_column($roles, 'role'));
        self::assertSame([['company' => 'Acme', 'role' => null, 'primary' => true, 'primary_contact' => true, 'purposes' => ['technical']]], $export['companies']);
        $this->service(CrmPersonalDataInterface::class)->erase($bob);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_role_assignment WHERE subject_id = ?', [$bob]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE contact_id = ?', [$bob]));

        // Purge deletes the roles.
        $this->records()->setRoles(SubjectType::Company, $acme, ['customer']);
        $this->records()->trash(SubjectType::Company, $acme, $this->reader()->record(SubjectType::Company, $acme)->version);
        $this->records()->purge(SubjectType::Company, $acme);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_role_assignment'));
    }

    public function testMergesKeepOnePrimaryAndUniteRolesAndPurposes(): void
    {
        $this->boot();
        $this->admin();
        $acme  = $this->company('Acme');
        $beta  = $this->company('Beta');
        $keep  = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'L'], roles: ['customer']));
        $other = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'L'], roles: ['customer', 'supplier']));
        $bob   = $this->contact('Bob', 'B');
        $this->records()->affiliate($keep, $acme, null, true, false, ['billing']);
        $this->records()->affiliate($other, $acme, null, false, true, ['orders']);
        $this->records()->affiliate($other, $beta, null, true, true, ['technical']);
        $this->records()->affiliate($bob, $beta, null, true, false);
        $merges = $this->service(MergeServiceInterface::class);
        self::assertSame(2, $merges->preview(SubjectType::Contact, $keep, $other)->moves['roles']);
        $merges->merge(SubjectType::Contact, $keep, $other, [], $this->reader()->record(SubjectType::Contact, $keep)->version, $this->reader()->record(SubjectType::Contact, $other)->version);
        $view = $this->reader()->record(SubjectType::Contact, $keep);
        self::assertSame(['customer', 'supplier'], $view->roles);
        self::assertSame([[$acme, true, true, ['billing', 'orders']], [$beta, false, true, ['technical']]], array_map(static fn (AffiliationView $a): array => [$a->companyId, $a->primary, $a->primaryContact, $a->purposes], $view->affiliations));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_role_assignment WHERE subject_id = ?', [$other]));

        // Companies: the survivor keeps its primary contact; the merged company's one becomes a plain contact.
        $gamma = $this->company('Gamma');
        $cyd   = $this->contact('Cyd', 'C');
        $this->records()->affiliate($cyd, $gamma, null, true, true);
        $this->records()->affiliate($bob, $gamma, null, false, true);
        $this->records()->setRoles(SubjectType::Company, $gamma, ['partner']);
        $merges->merge(SubjectType::Company, $beta, $gamma, [], $this->reader()->record(SubjectType::Company, $beta)->version, $this->reader()->record(SubjectType::Company, $gamma)->version);
        self::assertSame([$keep], $this->column('SELECT contact_id FROM nowo_crm_affiliation WHERE company_id = ? AND is_primary_contact = ?', [$beta, true]));
        self::assertSame(['partner'], $this->reader()->record(SubjectType::Company, $beta)->roles);
        self::assertSame(RecordStatus::Merged, $this->reader()->record(SubjectType::Company, $gamma)->status);

        // A survivor without a primary contact takes the merged company's.
        $delta = $this->company('Delta');
        $eps   = $this->company('Epsilon');
        $this->records()->affiliate($cyd, $delta, null, false, false);
        $this->records()->affiliate($cyd, $eps, null, false, true, ['general']);
        $this->records()->affiliate($bob, $eps, null, false, true);
        $merges->merge(SubjectType::Company, $delta, $eps, [], $this->reader()->record(SubjectType::Company, $delta)->version, $this->reader()->record(SubjectType::Company, $eps)->version);
        self::assertSame([$bob], $this->column('SELECT contact_id FROM nowo_crm_affiliation WHERE company_id = ? AND is_primary_contact = ?', [$delta, true]));
        self::assertSame(',general,', $this->text('SELECT purpose_keys FROM nowo_crm_affiliation WHERE company_id = ? AND contact_id = ?', [$delta, $cyd]));
    }

    public function testImportAndPages(): void
    {
        $this->boot(['import' => ['chunk_rows' => 10]]);
        $this->admin();
        $acme = $this->company('Acme');
        $this->contact('Old', 'Primary', 'old@x.test');
        $this->records()->affiliate($this->reader()->list(new ListQuery(SubjectType::Contact, 'old@'))->rows[0]->id, $acme, null, true, true);
        $imports = $this->service(ImportServiceInterface::class);
        $csv     = "Email,Last name,Company,Role,Purpose,Primary\nada@x.test,A,Acme,customer;supplier,billing,yes\nbob@x.test,B,Acme,customer,orders,no\ncyd@x.test,C,,Customer,,\ndee@x.test,D,Acme,,vendor,\neve@x.test,E,Acme,,,maybe\nold@x.test,Primary,Acme,partner,technical,\n";
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'p.csv', $csv);
        $preview = $imports->preview($id);
        self::assertEquals(['Email' => 'email', 'Last name' => 'last_name', 'Company' => 'company', 'Role' => 'roles', 'Purpose' => 'purposes', 'Primary' => 'primary_contact'], $preview->mapping);
        self::assertSame([[], [], ['roles' => 'error.role_unknown'], ['purposes' => 'error.purpose_unknown'], ['primary_contact' => 'error.invalid']], array_map(static fn (ImportRowView $r): array => $r->errors, array_slice($preview->rows, 0, 5)));
        $imports->confirm($id, $preview->mapping, DuplicatePolicy::Update, $preview->version);
        self::assertTrue($imports->run($id));
        self::assertSame(['created' => 2, 'updated' => 1, 'duplicate' => 0, 'invalid' => 3, 'limit' => 0], $imports->preview($id)->counts);
        $ada = $this->reader()->list(new ListQuery(SubjectType::Contact, 'ada@'))->rows[0];
        self::assertSame(['customer', 'supplier'], $ada->roles);
        $company = $this->reader()->record(SubjectType::Company, $acme);
        self::assertSame([[$ada->id, true, ['billing']]], array_values(array_map(static fn (AffiliationView $a): array => [$a->contactId, $a->primaryContact, $a->purposes], array_filter($company->affiliations, static fn (AffiliationView $a): bool => $a->primaryContact))));
        self::assertSame(['partner'], $this->reader()->list(new ListQuery(SubjectType::Contact, 'old@'))->rows[0]->roles, 'an import adds roles');
        self::assertSame(',technical,', $this->text("SELECT a.purpose_keys FROM nowo_crm_affiliation a JOIN nowo_crm_contact c ON c.id = a.contact_id WHERE c.email_key = 'old@x.test'"));
        // A company import with roles.
        $cid = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'c.csv', "Name,Roles\nZeta,supplier\n");
        $imports->confirm($cid, ['Name' => 'name', 'Roles' => 'roles'], DuplicatePolicy::Skip, $imports->preview($cid)->version);
        self::assertTrue($imports->run($cid));
        self::assertSame(['supplier'], $this->reader()->list(new ListQuery(SubjectType::Company, 'zeta'))->rows[0]->roles);

        // Pages: the form holds the roles; the list filters and shows them; the company page sets the primary contact.
        $page = $this->client->request('GET', '/crm/companies/' . $acme . '/edit');
        $form = $page->filter('form[name="crm_record"]')->form();
        $box  = $form['crm_record[roles]'];
        self::assertIsArray($box);
        foreach ($box as $field) {
            self::assertInstanceOf(ChoiceFormField::class, $field);
            $value = $field->availableOptionValues()[0] ?? '';
            if ($value === 'customer' || $value === 'partner') {
                $field->tick();
            } else {
                $field->untick();
            }
        }
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $show = $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertSame(['customer', 'partner'], $show->filter('[data-testid="crm-roles"] [data-role]')->each(static fn ($n): string => (string) $n->attr('data-role')));
        self::assertStringContainsString('Primary contact', $show->filter('[data-testid="crm-primary-contact"]')->text());
        self::assertStringContainsString('Billing', $show->filter('[data-testid="crm-affiliations"]')->text());
        $this->client->submit($show->filter('form[action*="/primary-contact/"]')->first()->form());
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE is_primary_contact = ?', [true]));
        $list = $this->client->request('GET', '/crm/companies?role=partner');
        self::assertStringContainsString('Partner', $list->filter('[data-testid="crm-list-company"]')->text());
        self::assertStringNotContainsString('Zeta', $list->filter('[data-testid="crm-list-company"]')->text());
        $list = $this->client->request('GET', '/crm/companies?role=nope');
        self::assertStringContainsString('Zeta', $list->filter('[data-testid="crm-list-company"]')->text(), 'an unknown role is ignored');
        $this->client->request('GET', '/crm/contacts?purpose=billing&primary_contact=1');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $contact = $this->client->request('GET', '/crm/contacts/' . $ada->id);
        $form    = $contact->filter('[data-testid="crm-affiliation-form"] form')->form(['crm_affiliation[company]' => $acme, 'crm_affiliation[primary_contact]' => '1']);
        $box     = $form['crm_affiliation[purposes]'];
        self::assertIsArray($box);
        foreach ($box as $field) {
            self::assertInstanceOf(ChoiceFormField::class, $field);
            if (($field->availableOptionValues()[0] ?? '') === 'orders') {
                $field->tick();
            }
        }
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(',orders,', $this->text('SELECT purpose_keys FROM nowo_crm_affiliation WHERE contact_id = ? AND company_id = ?', [$ada->id, $acme]));
        self::assertSame([$ada->id], $this->column('SELECT contact_id FROM nowo_crm_affiliation WHERE is_primary_contact = ?', [true]));
    }

    private function reader(): CrmReaderInterface
    {
        return $this->service(CrmReaderInterface::class);
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<string>
     */
    private function column(string $sql, array $params = []): array
    {
        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $this->connection()->fetchFirstColumn($sql, $params));
    }
}
