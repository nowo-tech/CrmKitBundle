<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Bridge\SearchKit\CrmDealSearchDefinition;
use Nowo\CrmKitBundle\Bridge\SearchKit\CrmRecordSearchDefinition;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Tests\Integration\Database\CrmTestCase;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function class_exists;

/**
 * The CRM's SearchKit groups beyond SearchKit's contract (0.3.0): wiring and switches, labels, icons, subtitles,
 * "see all" links, the company scope of contacts, resource scoping in SQL, the amount of deals behind `amounts_view`
 * and a hidden company never revealed through its deals.
 */
final class SearchKitGroupsTest extends CrmTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
        }
    }

    public function testTheGroupsAreRegisteredOnlyWhenSwitchedOn(): void
    {
        $this->boot(['pipeline' => ['enabled' => true]]);
        foreach (['company', 'contact', 'deal'] as $type) {
            self::assertTrue($this->registered('nowo_crm_kit.search.' . $type), $type);
        }
        $this->boot();
        self::assertTrue($this->registered('nowo_crm_kit.search.company'));
        self::assertFalse($this->registered('nowo_crm_kit.search.deal'), 'deals need pipeline.enabled');
        $this->boot(['search' => ['enabled' => false]]);
        self::assertFalse($this->registered('nowo_crm_kit.search.company'));
        $this->boot(['ui' => ['enabled' => false]]);
        self::assertFalse($this->registered('nowo_crm_kit.search.contact'));
    }

    public function testCompaniesAndContacts(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme', ['email' => 'hello@acme.test', 'city' => 'Bilbao', 'tax_id' => 'B-1234']);
        $this->company('Other');
        $ada = $this->contact('Ada', 'Lovelace', 'ada@x.test', ['city' => 'Madrid']);
        $this->contact('Adam', 'Smith');
        $this->records()->affiliate($ada, $acme, null, true);
        $companies = $this->definition('company');
        $contacts  = $this->definition('contact');

        self::assertSame(['crm_companies', 'company', 'tabler:building', 'Companies'], [$companies->group(), $companies->subjectType(), $companies->icon(), $companies->label($this->query('x'))]);
        self::assertSame(['crm_contacts', 'contact', 'tabler:user', 'Contacts'], [$contacts->group(), $contacts->subjectType(), $contacts->icon(), $contacts->label($this->query('x'))]);
        self::assertSame('Empresas', $companies->label(new SearchQuery('x', null, 5, null, false, 'ana', [], 'es')));
        self::assertSame('r.id', $companies->idField());

        $group = $this->provider($companies)->search($this->query('acm'));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['Acme', 'hello@acme.test · Bilbao', 'tabler:building', 1, '/crm/companies?q=acm'], [$group->items[0]->title, $group->items[0]->subtitle, $group->items[0]->icon, $group->total, $group->moreUrl]);
        self::assertSame('/crm/companies/' . $acme, $group->items[0]->url);
        self::assertSame('Acme', $this->provider($companies)->search($this->query('b1234'))?->items[0]->title, 'the tax id key');

        // Contacts: by name, and narrowed to one company by its id as the scope.
        self::assertSame(['Ada Lovelace', 'Adam Smith'], $this->titles($this->provider($contacts)->search($this->query('ada'))));
        $scoped = $this->provider($contacts)->search($this->query('ada', $acme));
        self::assertSame(['Ada Lovelace'], $this->titles($scoped));
        self::assertSame('ada@x.test · Madrid', $scoped?->items[0]->subtitle);
        self::assertNull($this->provider($contacts)->search($this->query('ada', '01900000-0000-7000-8000-00000000ffff')), 'an unknown company');
        self::assertNull($this->provider($contacts)->search($this->query('ada', 'nope')), 'not a company id');
        self::assertNull($this->provider($companies)->search($this->query('acme', $acme)), 'companies take no scope');
        $this->records()->trash(SubjectType::Company, $acme, $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$acme]));
        self::assertNull($this->provider($contacts)->search($this->query('ada', $acme)), 'a trashed company');

        // Recents: only this group's record page with a record id.
        self::assertSame($ada, $contacts->referenceId(new SearchReference('/crm/contacts/' . $ada, 'crm_contacts', 'nowo_crm_kit_show', ['type' => 'contacts', 'id' => $ada])));
        self::assertNull($contacts->referenceId(new SearchReference('/x', 'crm_contacts', 'nowo_crm_kit_show', ['type' => 'companies', 'id' => $ada])));
        self::assertNull($contacts->referenceId(new SearchReference('/x', 'crm_contacts', 'nowo_crm_kit_list', ['type' => 'contacts', 'id' => $ada])));
        self::assertNull($contacts->referenceId(new SearchReference('/x', 'crm_contacts', 'nowo_crm_kit_show', ['type' => 'contacts', 'id' => 'ABC'])));
        self::assertNull($contacts->referenceId(new SearchReference('/x', 'crm_contacts', 'nowo_crm_kit_show', ['type' => 'contacts', 'id' => $ada, 'x' => 'y'])));

        // No view, no tenant: no group and no hint.
        $this->ctx()->grants = [];
        self::assertNull($this->provider($contacts)->search($this->query('ada')));
        self::assertSame([], $contacts->filterVisible([['id' => $ada, 'tenantId' => 'acme', 'ownerId' => null]], 'acme', $this->query('ada')));
        $this->admin();
        self::assertSame([], $contacts->filterVisible([['id' => $ada, 'tenantId' => 'acme', 'ownerId' => null]], 'beta', $this->query('ada')), 'another tenant than the call');
        self::assertSame([], $contacts->filterVisible([['id' => $ada, 'tenantId' => 'beta', 'ownerId' => null]], 'acme', $this->query('ada')), 'a row of another tenant');
        $this->ctx()->grants = [];
        $dql                 = $contacts->baseQuery('acme', $this->query('ada'));
        self::assertStringContainsString('r.ownerId IS NULL', $dql->getDQL(), 'without a context nothing but ownerless rows');
        $this->admin();
        $this->ctx()->tenant = null;
        self::assertNull($contacts->tenant($this->query('ada')));
        self::assertFalse($contacts->featureEnabled('acme', $this->query('ada')));
    }

    public function testResourceScopingInSql(): void
    {
        $this->boot(['access_checker' => null, 'pipeline' => ['enabled' => true], 'security' => ['access_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'manage_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'resource_scoping' => true]]);
        $this->signIn('root');
        $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Scoped mine'], 'ana'));
        $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Scoped free']));
        $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Scoped theirs'], 'leo'));
        self::assertSame(['Scoped free', 'Scoped mine', 'Scoped theirs'], $this->titles($this->provider($this->definition('company'))->search($this->query('scoped', null, 'root'))), 'settings_roles see every record');
        $this->signIn('ana');
        self::assertSame(['Scoped free', 'Scoped mine'], $this->titles($this->provider($this->definition('company'))->search($this->query('scoped'))));
        $deals = $this->definition('deal')->baseQuery('acme', $this->query('scoped'));
        self::assertStringContainsString('co.ownerId IS NULL OR co.ownerId IN (:crm_owners)', $deals->getDQL(), 'the company of a deal under the same visibility');
        $this->signOut();
        $deals = $this->definition('deal')->baseQuery('acme', $this->query('scoped'));
        self::assertStringContainsString('AND co.ownerId IS NULL', $deals->getDQL(), 'without a context only ownerless companies');
        $this->signIn('leo');
        self::assertSame(['Scoped free', 'Scoped theirs'], $this->titles($this->provider($this->definition('company'))->search($this->query('scoped', null, 'leo'))));
    }

    public function testDeals(): void
    {
        $this->boot(['pipeline' => ['enabled' => true]]);
        $this->actAs('ana', 'view', 'edit', 'manage', 'amounts_view');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $acme   = $this->company('Acme');
        $secret = $this->company('Secret');
        $deals  = $this->service(DealService::class);
        $big    = $deals->create(new DealInput('Big deal', $funnel, companyId: $acme, amount: new Money('12000.50', 'EUR')));
        $hush   = $deals->create(new DealInput('Quiet deal', $funnel, companyId: $secret));
        $deal   = $this->definition('deal');
        self::assertInstanceOf(CrmDealSearchDefinition::class, $deal);
        self::assertSame(['crm_deals', 'deal', 'tabler:briefcase', 'Deals'], [$deal->group(), $deal->subjectType(), $deal->icon(), $deal->label($this->query('x'))]);

        $group = $this->provider($deal)->search($this->query('big'));
        self::assertSame(['Big deal', 'Open · Acme · 12000.5 EUR', '/crm/deals/' . $big, '/crm/deals?q=big'], [$group?->items[0]->title, $group?->items[0]->subtitle, $group?->items[0]->url, $group?->moreUrl]);
        self::assertSame(['Big deal'], $this->titles($this->provider($deal)->search($this->query('acme'))), 'by company name');
        self::assertSame(['Quiet deal', 'Big deal'], $this->titles($this->provider($deal)->search($this->query('open'))), 'by stage name, newest change first');
        self::assertNull($this->provider($deal)->search($this->query('12000')), 'never by amount');

        // Without amounts_view, no amount.
        $this->ctx()->grants['acme']['ana'] = ['view'];
        self::assertSame('Open · Acme', $this->provider($deal)->search($this->query('big'))?->items[0]->subtitle);

        // A company the person may not view: its name is neither shown nor a match.
        $this->ctx()->denied[] = 'view:' . $secret;
        self::assertNull($this->provider($deal)->search($this->query('secret')));
        self::assertSame('Open', $this->provider($deal)->search($this->query('quiet'))?->items[0]->subtitle);
        self::assertSame(['Quiet deal'], $this->titles($this->provider($deal)->search($this->query('quiet open'))));

        // A deal the person may not view never appears.
        $this->ctx()->denied[] = 'view:' . $big;
        self::assertNull($this->provider($deal)->search($this->query('big')));

        self::assertSame($hush, $deal->referenceId(new SearchReference('/crm/deals/' . $hush, 'crm_deals', 'nowo_crm_kit_deal_show', ['id' => $hush])));
        self::assertNull($deal->referenceId(new SearchReference('/x', 'crm_deals', 'nowo_crm_kit_show', ['id' => $hush])));
    }

    private function definition(string $type): CrmRecordSearchDefinition|CrmDealSearchDefinition
    {
        $definition = $this->kernel->getContainer()->get('nowo_crm_kit.search.' . $type);
        self::assertTrue($definition instanceof CrmRecordSearchDefinition || $definition instanceof CrmDealSearchDefinition);

        return $definition;
    }

    private function registered(string $id): bool
    {
        return $this->kernel->getContainer()->has($id);
    }

    private function provider(EntitySearchDefinition $definition): EntitySearchProvider
    {
        return new EntitySearchProvider($definition, new EventDispatcher());
    }

    private function query(string $text, ?string $scope = null, string $viewer = 'ana'): SearchQuery
    {
        return SearchQuery::forUserIdentifier($viewer, $text, 10, $scope);
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->title, $group->items) : [];
    }

    private function signOut(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(null);
    }

    private function signIn(string $user): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $roles = $user === 'root' ? ['ROLE_ADMIN'] : ['ROLE_USER'];
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, 'x', $roles), 'main', $roles));
    }
}
