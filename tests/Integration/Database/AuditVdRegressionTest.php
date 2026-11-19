<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\DriverManager;
use Nowo\CrmKitBundle\Application\ActorPersonalData;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\ImportRowView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmActorPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmActorDataStore;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordVisibilityEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function array_map;
use function in_array;

/**
 * Regression tests of audit VD (0.2.1): the anonymised placeholder never acts or owns; an anonymise batch locks only
 * its own rows; Cancel never ends an import a claim just took; Continue / Continue and Continue / resume settle each row
 * once.
 */
final class AuditVdRegressionTest extends CrmTestCase
{
    public function testThePlaceholderNeverActsOrOwns(): void
    {
        $this->boot(['access_checker' => null, 'security' => ['access_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'manage_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'resource_scoping' => true]]);
        $this->signIn('root', ['ROLE_ADMIN']);
        $id = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Secret', 'email' => 's@x.test'], 'ana'));
        $this->service(CrmActorPersonalDataInterface::class)->anonymiseActor('ana');
        self::assertSame(ActorRef::ANONYMISED, $this->text('SELECT owner_id FROM nowo_crm_contact WHERE id = ?', [$id]));

        // Somebody else: denied under resource_scoping.
        $this->signIn('bob', ['ROLE_USER']);
        try {
            $this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $id);
            self::fail('bob opened an anonymised record');
        } catch (AccessDeniedException) {
        }

        // A person whose identifier is literally the placeholder (or the demo's shared actor) is no actor at all.
        foreach ([ActorRef::ANONYMISED, 'anonymous'] as $identifier) {
            $this->signIn($identifier, ['ROLE_USER']);
            try {
                $this->service(CrmReaderInterface::class)->list(new ListQuery(SubjectType::Contact));
                self::fail($identifier . ' acted');
            } catch (NotAvailableException) {
            }
        }

        // A visibility listener that adds the placeholder shows nothing more.
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CrmKitEvents::RECORD_VISIBILITY, static function (RecordVisibilityEvent $event): void {
            $event->addOwner(ActorRef::ANONYMISED);
        });
        $this->signIn('bob', ['ROLE_USER']);
        $listed = array_map(static fn (RecordView $v): string => $v->id, $this->service(CrmReaderInterface::class)->list(new ListQuery(SubjectType::Contact))->rows);
        self::assertFalse(in_array($id, $listed, true));
    }

    public function testAHostCheckerOrAnOwnerInputCannotUseThePlaceholder(): void
    {
        $this->boot();
        $this->ctx()->owners['acme'][ActorRef::ANONYMISED] = 'Listed by a careless directory';
        $this->admin();
        try {
            $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'X'], ActorRef::ANONYMISED));
            self::fail('the placeholder became an owner');
        } catch (InvalidInputException $e) {
            self::assertSame(['owner' => 'error.owner_unknown'], $e->errors);
        }
        $this->actAs(ActorRef::ANONYMISED, 'view', 'edit', 'manage');
        $this->expectException(NotAvailableException::class);
        $this->service(CrmReaderInterface::class)->list(new ListQuery(SubjectType::Company));
    }

    public function testAnAnonymiseBatchLocksOnlyItsOwnRows(): void
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->boot();
        $this->actAs('leo', 'view', 'create', 'edit', 'manage');
        $leo = [];
        for ($i = 0; $i < 5; ++$i) {
            $leo[] = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Leo ' . $i], 'leo'));
        }
        $this->ctx()->tenant           = 'globex';
        $this->ctx()->owners['globex'] = ['ana' => 'Ana'];
        $this->actAs('ana', 'view', 'create', 'edit', 'manage');
        $ana = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Ana Co'], 'ana'));

        $store = $this->service(DoctrineCrmActorDataStore::class);
        $conn  = $this->connection();
        $other = DriverManager::getConnection($conn->getParams());
        self::boundLockWaits($other, 1);
        $conn->beginTransaction();
        try {
            self::assertSame([['tenant' => 'globex', 'key' => $ana]], $store->scan('company.owner', 'ana', null, 200, ActorPersonalData::PLACEHOLDER)['rows']);
            // Unrelated rows (another tenant, another person) stay writable while the batch is open.
            foreach ($leo as $company) {
                self::assertSame(1, $other->executeStatement("UPDATE nowo_crm_company SET city = 'x' WHERE id = ?", [$company]));
            }
            // The batch's own row is locked until the commit.
            try {
                $other->executeStatement("UPDATE nowo_crm_company SET city = 'y' WHERE id = ?", [$ana]);
                self::fail('the batch row was not locked');
            } catch (Throwable $e) {
                self::assertNotSame('the batch row was not locked', $e->getMessage());
            }
        } finally {
            $conn->rollBack();
            $other->close();
        }
    }

    public function testCancelAndContinueDoNotStepOnAFreshClaim(): void
    {
        $this->boot(['access_checker' => null, 'import' => ['chunk_rows' => 10]]);
        $this->signIn('root', ['ROLE_ADMIN']);
        $this->company('Acme');
        $imports = $this->service(ImportServiceInterface::class);
        $paused  = function (string $email) use ($imports): string {
            $id = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'p.csv', "email,last_name,company\n" . $email . ",New,Acme\n");
            $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Skip, 1);
            $this->clock()->sleep(3600);
            $this->signOut();
            self::assertSame(0, $this->service(ImportService::class)->resumeStale());
            $this->signIn('root', ['ROLE_ADMIN']);
            self::assertTrue($imports->preview($id)->awaitingPerson);

            return $id;
        };
        // Another worker's "Continue" claims the paused import (committed on its connection).
        $claimElsewhere = fn (string $id): int => (int) $this->connection()->executeStatement(
            "UPDATE nowo_crm_import SET claim_token = 'claim-elsewhere', claimed_at = ? WHERE id = ? AND claim_token IS NULL",
            [$this->clock()->now()->format('Y-m-d H:i:s'), $id],
        );

        // Cancel / Continue: the fresh claim wins; Cancel answers crm_import_running.
        $a = $paused('new@x.test');
        self::assertSame(1, $claimElsewhere($a));
        try {
            $imports->cancel($a);
            self::fail('cancelled under a fresh claim');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::IMPORT_RUNNING, $e->errorCode);
        }
        self::assertSame('running', $imports->preview($a)->status->value);

        // Continue / Continue: the late one joins the winner's claim; each row settles once.
        self::assertTrue($imports->run($a));
        self::assertSame(['created'], array_map(static fn (ImportRowView $r): string => $r->outcome->value, $imports->preview($a)->rows));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE email = 'new@x.test'"));

        // Continue / resume command: a fresh claim is never taken over; a paused import is never taken.
        $b = $paused('other@x.test');
        $this->signOut();
        self::assertSame(0, $this->service(ImportService::class)->resumeStale(), 'paused: left alone');
        self::assertSame(1, $claimElsewhere($b));
        self::assertSame(0, $this->service(ImportService::class)->resumeStale(), 'freshly claimed: left alone');
        self::assertSame('claim-elsewhere', $this->text('SELECT claim_token FROM nowo_crm_import WHERE id = ?', [$b]));
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $user, array $roles): void
    {
        $this->tokens()->setToken(new UsernamePasswordToken(new InMemoryUser($user, 'x', $roles), 'main', $roles));
    }

    private function signOut(): void
    {
        $this->tokens()->setToken(null);
    }

    private function tokens(): TokenStorageInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }
}
