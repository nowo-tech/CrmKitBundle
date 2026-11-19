<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use LogicException;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordAccessCheckEvent;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordVisibilityEvent;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function implode;
use function iterator_to_array;
use function range;

/**
 * REQ-UI-002 on the pages with the bundle's own checkers (no host access checker): anonymous ⇒ the firewall's entry
 * point (401 with HTTP Basic), a user without the area role ⇒ 403, an admin ⇒ 200; the area roles split the actions;
 * `nowo_crm_kit.record_access_check` listeners grant or deny one record; tenants stay apart.
 */
final class SecurityTest extends CrmTestCase
{
    public function testPagesFollowTheSecurityConfiguration(): void
    {
        $this->boot(['access_checker' => null, 'security' => ['manage_roles' => ['ROLE_USER', 'ROLE_ADMIN']]]);
        $this->ctx()->actor  = null;
        $this->ctx()->tenant = 'acme';
        self::assertSame(401, $this->httpStatus('GET', '/crm/companies'), 'anonymous: the firewall entry point');
        self::assertSame(403, $this->as('ana', 'GET', '/crm/companies'), 'no access role');
        self::assertSame(200, $this->as('root', 'GET', '/crm/companies'));
        self::assertSame(200, $this->as('root', 'GET', '/crm/companies/new'));

        // The guard answers each area, tenant-wide.
        $this->as('root', 'GET', '/crm/companies');
        $guard = $this->service(CrmKitGuard::class);
        self::assertTrue($guard->grants()[CrmPermission::Export->value]);

        // A record: a listener denies the view of one company (403 on its page), grants nothing else.
        $this->client->submit($this->client->request('GET', '/crm/companies/new')->filter('form[name="crm_record"]')->form(), ['crm_record[name]' => 'Acme']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page       = (string) $this->client->getResponse()->headers->get('Location');
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $seen = [];
        $dispatcher->addListener(CrmKitEvents::RECORD_ACCESS_CHECK, static function (RecordAccessCheckEvent $event) use (&$seen): void {
            $seen[] = $event->getAction() . ':' . $event->getActorId() . ':' . $event->getTenantKey();
            if ($event->getAction() === 'view') {
                $event->deny();
            }
        });
        self::assertSame(403, $this->as('root', 'GET', $page));
        self::assertContains('view:root:acme', $seen);

        // Another tenant: the same person and id ⇒ 404, never 403.
        $this->ctx()->tenant = 'beta';
        self::assertSame(404, $this->as('root', 'GET', $page));
    }

    public function testResourceScopingCoversListsSearchExportsImportsAndMerges(): void
    {
        $this->boot(['access_checker' => null, 'security' => ['access_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'manage_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'export_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'resource_scoping' => true]]);
        $this->signIn('root');
        $records = $this->records();
        $anaC    = $records->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ann', 'last_name' => 'Owned', 'email' => 'ana-c@x.test'], 'ana'));
        $leoC    = $records->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Leo', 'last_name' => 'Owned', 'email' => 'leo-c@x.test'], 'leo'));
        $records->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Dup', 'last_name' => 'One'], 'ana'));
        $leoDup = $records->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Dup', 'last_name' => 'One'], 'leo'));
        $records->create(new RecordInput(SubjectType::Company, ['name' => 'Anas Co'], 'ana'));
        $shared = $this->company('Shared');
        $gamma  = $this->company('Gamma');
        $records->affiliate($anaC, $shared, null, true);
        $leoAff = $records->affiliate($leoC, $shared, null, true, true);
        $links  = $this->service(HostLinkServiceInterface::class);
        foreach ([$anaC, $leoC] as $id) {
            $links->link(SubjectType::Contact, $id, new HostObjectRef('project', 'p1'));
        }

        // leo: only his own records and ownerless ones, in every read that returns several records.
        $this->signIn('leo');
        $reader = $this->service(CrmReader::class);
        $ids    = static fn (array $views): array => array_map(static fn (mixed $v): string => $v instanceof RecordView ? $v->id : '', $views);
        $list   = $reader->list(new ListQuery(SubjectType::Contact));
        self::assertSame([[$leoDup, $leoC], 2], [$ids($list->rows), $list->total], 'list and total');
        $found = $reader->search(SubjectType::Contact, 'x.test', 10);
        self::assertNotNull($found);
        self::assertSame([[$leoC], 1], [$ids($found[0]), $found[1]], 'search and its total');
        self::assertSame([], $reader->duplicates(SubjectType::Contact, $leoDup), 'duplicates');
        self::assertSame([$gamma, $shared], $ids($reader->list(new ListQuery(SubjectType::Company))->rows), 'the company picker');
        self::assertSame([$leoC], array_map(static fn (AffiliationView $a): string => $a->contactId, $reader->record(SubjectType::Company, $shared)->affiliations), 'a page\'s related records');
        self::assertSame([$leoC], array_map(static fn (SubjectRef $r): string => $r->id->value, $links->subjectsLinkedTo(new HostObjectRef('project', 'p1'))));
        foreach ($reader->timeline(SubjectType::Company, $shared) as $entry) {
            self::assertNotSame($anaC, $entry->subjectId, 'the timeline of a company');
        }
        $csv = implode('', iterator_to_array($this->service(CrmExporterInterface::class)->csv(new ListQuery(SubjectType::Contact)), false));
        self::assertStringContainsString('leo-c@x.test', $csv);
        self::assertStringNotContainsString('ana-c@x.test', $csv, 'the CSV export');
        try {
            $reader->record(SubjectType::Contact, $anaC);
            self::fail('another owner\'s record');
        } catch (AccessDeniedException) {
        }

        // A visibility of no owner at all (a host port may answer it) leaves the ownerless records only.
        self::assertSame(0, $this->service(CrmStoreInterface::class)->list(new TenantRef('acme'), new ListQuery(SubjectType::Contact), [])[1]);

        // More visible owners than one query may bind: a clear refusal (allowAll() and record checks instead).
        try {
            $this->service(CrmStoreInterface::class)->list(new TenantRef('acme'), new ListQuery(SubjectType::Contact), array_map(static fn (int $i): string => 'o' . $i, range(1, 1001)));
            self::fail('1001 owners bound');
        } catch (LogicException $e) {
            self::assertStringContainsString('allowAll()', $e->getMessage());
        }

        // A taken e-mail names the other contact only to someone who may see it.
        try {
            $records->create(new RecordInput(SubjectType::Contact, ['last_name' => 'X', 'email' => 'ana-c@x.test']));
            self::fail('a second contact with one e-mail');
        } catch (InvalidInputException $e) {
            self::assertSame([['email' => 'error.email_taken'], []], [$e->errors, $e->params]);
        }

        // An import that would update ana's contact is refused per row (nothing echoed); ana's company is unknown to
        // leo, so the row is stored without that link.
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'u.csv', "email,last_name,company\nana-c@x.test,Changed,\nnew@x.test,New,Anas Co\n");
        $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Update, 1);
        self::assertTrue($imports->run($id));
        $rows = $imports->preview($id)->rows;
        self::assertSame([['invalid', 'error.forbidden', null], ['created', null]], [[$rows[0]->outcome->value, $rows[0]->message, $rows[0]->recordId], [$rows[1]->outcome->value, $rows[1]->message]]);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE contact_id = ?', [(string) $rows[1]->recordId]), 'no link to an invisible company');
        self::assertSame('Owned', $this->text('SELECT last_name FROM nowo_crm_contact WHERE id = ?', [$anaC]));
        $skip = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 's.csv', "email,last_name\nana-c@x.test,X\n");
        $imports->confirm($skip, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        self::assertTrue($imports->run($skip));
        self::assertSame(['duplicate', null], [$imports->preview($skip)->rows[0]->outcome->value, $imports->preview($skip)->rows[0]->recordId], 'no id of a contact leo may not see');

        // A team listener widens the set for lists and record pages alike.
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CrmKitEvents::RECORD_VISIBILITY, static function (RecordVisibilityEvent $event): void {
            $event->addOwner('ana');
        });
        self::assertSame(5, $reader->list(new ListQuery(SubjectType::Contact))->total);
        self::assertSame($anaC, $reader->record(SubjectType::Contact, $anaC)->id);

        // Merging needs delete on the merged record; removing a company's primary contact needs edit on the company.
        $dispatcher->addListener(CrmKitEvents::RECORD_ACCESS_CHECK, static function (RecordAccessCheckEvent $event) use ($anaC, $shared, $gamma): void {
            if (($event->getAction() === 'delete' && $event->getSubjectId() === $anaC) || ($event->getAction() === 'edit' && $event->getSubjectId() === $shared) || ($event->getAction() === 'view' && $event->getSubjectId() === $gamma)) {
                $event->deny();
            }
        });
        $merges = $this->service(MergeServiceInterface::class);
        try {
            $merges->merge(SubjectType::Contact, $leoC, $anaC, [], $reader->record(SubjectType::Contact, $leoC)->version, $reader->record(SubjectType::Contact, $anaC)->version);
            self::fail('merged without delete on the merged record');
        } catch (AccessDeniedException) {
        }
        try {
            $records->unaffiliate($leoAff);
            self::fail('the company\'s primary contact removed without edit on the company');
        } catch (AccessDeniedException) {
        }
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE id = ? AND is_primary_contact = ?', [$leoAff, true]));

        // A visible company a listener hides from leo: the row naming it is refused.
        $denied = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'g.csv', "email,last_name,company\ng@x.test,G,Gamma\n");
        $imports->confirm($denied, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Update, 1);
        self::assertTrue($imports->run($denied));
        self::assertSame('error.forbidden', $imports->preview($denied)->rows[0]->message);

        // The same taken e-mail named to someone who may see the contact.
        $this->signIn('root');
        try {
            $records->create(new RecordInput(SubjectType::Contact, ['last_name' => 'X', 'email' => 'ana-c@x.test']));
            self::fail('a second contact with one e-mail');
        } catch (InvalidInputException $e) {
            self::assertSame(['%id%' => $anaC], $e->params);
        }
    }

    public function testTheResumeCommandLeavesRowsThatNeedARecordDecisionToAPerson(): void
    {
        $this->boot(['access_checker' => null, 'import' => ['chunk_rows' => 10]]);
        $this->signIn('root');
        $records = $this->records();
        $ada     = $records->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Ada', 'email' => 'ada@x.test']));
        $this->company('Acme');
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'r.csv', "email,last_name,company\nnew@x.test,New,\nada@x.test,Changed,\nbob@x.test,Bob,Acme\n");
        $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Update, 1);

        // A killed worker: the claim goes stale and the resume command runs with nobody signed in.
        $this->clock()->sleep(3600);
        $this->signOut();
        self::assertSame(0, $this->service(ImportService::class)->resumeStale());
        $this->signIn('root');
        $rows = $imports->preview($id)->rows;
        self::assertSame(['created', 'pending', 'pending'], [$rows[0]->outcome->value, $rows[1]->outcome->value, $rows[2]->outcome->value], 'the update and the company row wait for a person');
        self::assertSame('Ada', $this->text('SELECT last_name FROM nowo_crm_contact WHERE id = ?', [$ada]));
        // The pause released the claim: the import waits for a person, and later resume runs leave it alone.
        self::assertTrue($imports->preview($id)->awaitingPerson);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_import WHERE id = ? AND claim_token IS NULL', [$id]));
        $before = $this->connection()->fetchAssociative('SELECT version, claimed_at, rows_done FROM nowo_crm_import WHERE id = ?', [$id]);
        $this->clock()->sleep(3600);
        $this->signOut();
        self::assertSame(0, $this->service(ImportService::class)->resumeStale());
        self::assertSame($before, $this->connection()->fetchAssociative('SELECT version, claimed_at, rows_done FROM nowo_crm_import WHERE id = ?', [$id]), 'a second resume run does not touch it');
        $this->signIn('root');

        // The next person's "Continue" settles them at once (no stale claim to wait for), under their own access.
        self::assertTrue($imports->run($id));
        self::assertFalse($imports->preview($id)->awaitingPerson);
        $rows = $imports->preview($id)->rows;
        self::assertSame(['created', 'updated', 'created'], [$rows[0]->outcome->value, $rows[1]->outcome->value, $rows[2]->outcome->value]);
        self::assertSame('Changed', $this->text('SELECT last_name FROM nowo_crm_contact WHERE id = ?', [$ada]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));

        // A new contact that names a company waits for a person too.
        $named = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'n.csv', "email,last_name,company\ncyd@x.test,Cyd,Acme\n");
        $imports->confirm($named, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Skip, 1);
        $this->clock()->sleep(3600);
        $this->signOut();
        self::assertSame(0, $this->service(ImportService::class)->resumeStale());
        $this->signIn('root');
        self::assertSame('pending', $imports->preview($named)->rows[0]->outcome->value);
        self::assertTrue($imports->preview($named)->awaitingPerson);
        // Continue right away, one chunk at a time: the first call claims it, the claim is held while it runs.
        $this->clock()->sleep(1);
        self::assertTrue($imports->run($named, 1));
        self::assertSame('created', $imports->preview($named)->rows[0]->outcome->value);

        // A paused import can be cancelled at once too (no stale claim to wait for).
        $other = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'o.csv', 'email,last_name,company
dot@x.test,Dot,Acme
');
        $imports->confirm($other, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Skip, 1);
        $this->clock()->sleep(3600);
        $this->signOut();
        self::assertSame(0, $this->service(ImportService::class)->resumeStale());
        $this->signIn('root');
        $imports->cancel($other);
        self::assertSame('cancelled', $imports->preview($other)->status->value);
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
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, 'x', $user === 'root' ? ['ROLE_ADMIN'] : ['ROLE_USER']), 'main', $user === 'root' ? ['ROLE_ADMIN'] : ['ROLE_USER']));
    }

    private function as(string $user, string $method, string $path): int
    {
        $this->client->loginUser(new InMemoryUser($user, 'x', $user === 'root' ? ['ROLE_ADMIN'] : ['ROLE_USER']), 'main');
        $this->client->request($method, $path);

        return $this->client->getResponse()->getStatusCode();
    }
}
