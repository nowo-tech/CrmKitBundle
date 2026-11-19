<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\CrmKitBundle\Application\ActorPersonalData;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\ActorAnonymised;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\CrmKitBundle\Tests\Fixtures\TestActorResolver;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_filter;
use function array_keys;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function sprintf;

/**
 * The actor id of a signed-in person (0.2.1): a host resolver with stable ids, names resolved once per page; the
 * system API that exports and anonymises a person's references across tenants, in batches, events after the commits,
 * idempotent. On the shared entity manager (`entity_manager: null`), which the bundle never clears or resets.
 */
final class ActorDataTest extends CrmTestCase
{
    protected function host(): Host
    {
        return Host::singleEntityManager();
    }

    public function testAStableActorIdSurvivesAnIdentifierChangeAndNamesComeOncePerPage(): void
    {
        $this->boot(['access_checker' => null, 'security' => ['actor_resolver' => 'test.actor_resolver']]);
        $resolver = $this->kernel->getContainer()->get('test.actor_resolver');
        self::assertInstanceOf(TestActorResolver::class, $resolver);
        $stable = TestActorResolver::IDS['root'];

        $this->signIn('root');
        $id = $this->company('Acme');
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $id, 'first');
        self::assertSame($stable, $this->text('SELECT created_by_id FROM nowo_crm_company WHERE id = ?', [$id]), 'the stable id, not the identifier');

        // The person's sign-in identifier (an e-mail) changes: still the same actor.
        $this->signIn('root-renamed@x.test');
        $reader = $this->service(CrmReaderInterface::class);
        $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Madrid']), $reader->record(SubjectType::Company, $id)->version);
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $id, 'second');
        self::assertSame($stable, $this->text('SELECT updated_by_id FROM nowo_crm_company WHERE id = ?', [$id]));
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_note WHERE author_id = ?', [$stable]));

        $resolver->calls = [];
        $notes           = $reader->notes(SubjectType::Company, $id);
        self::assertSame(['Root Person', 'Root Person'], array_map(static fn (NoteView $n): ?string => $n->authorName, $notes));
        $timeline = $reader->timeline(SubjectType::Company, $id);
        self::assertNotSame([], $timeline);
        self::assertSame(['Root Person'], array_values(array_unique(array_map(static fn (ActivityView $a): ?string => $a->actorName, $timeline))), 'the same person before and after the change');
        self::assertSame([[$stable], [$stable]], $resolver->calls, 'one resolve() per page, distinct ids');
    }

    public function testExportAndAnonymiseAPersonAcrossTenants(): void
    {
        $this->boot();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $tool->dropSchema([$em->getClassMetadata(HostNote::class)]);
        $tool->createSchema([$em->getClassMetadata(HostNote::class)]);
        $hostNote = new HostNote('01900000-0000-7000-8000-000000000001', 'host');
        $em->persist($hostNote);
        $em->flush();

        // ana's references in acme, leo's alongside.
        $this->everything('acme', 'ana');
        $reader = $this->service(CrmReaderInterface::class);
        $a      = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Acme'], 'ana'));
        $c      = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Client', 'email' => 'c@x.test'], 'leo'));
        $this->records()->update($c, new RecordInput(SubjectType::Contact, [], 'ana'), $reader->record(SubjectType::Contact, $c)->version);
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $a, 'by ana');
        $this->service(HostLinkServiceInterface::class)->link(SubjectType::Company, $a, new HostObjectRef('issue', '1'));
        $this->records()->setRoles(SubjectType::Company, $a, ['customer']);
        $this->service(ConsentServiceInterface::class)->record($c, 'newsletter', true, ConsentSource::Form, 'web form');
        $this->service(CrmSettingsInterface::class)->update(7, 1, 1, 1, 0);
        $this->service(ImportServiceInterface::class)->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "email,last_name\nx@x.test,X\n");
        $m1 = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Twin'], 'ana'));
        $m2 = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Twin 2'], 'ana'));
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $m1, $m2, [], $reader->record(SubjectType::Contact, $m1)->version, $reader->record(SubjectType::Contact, $m2)->version);
        for ($i = 1; $i <= ActorPersonalData::BATCH + 5; ++$i) {
            $this->connection()->insert('nowo_crm_activity', ['id' => sprintf('01900000-0000-7000-8000-%012d', $i), 'tenant_id' => 'acme', 'subject_type' => 'company', 'subject_id' => $a, 'kind' => 'updated', 'actor_id' => 'ana', 'params' => '{}', 'occurred_at' => '2026-11-18 09:00:00']);
        }
        $this->everything('acme', 'leo');
        $l = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Leo Co'], 'leo'));
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $a, 'by leo');
        // And in globex.
        $this->ctx()->tenant = 'globex';
        $this->everything('globex', 'ana');
        $g = $this->company('Globex');

        $data   = $this->service(CrmPersonalDataInterface::class);
        $export = $data->exportActor('ana');
        self::assertSame('ana', $export['actor']);
        self::assertSame(['acme', 'globex'], array_keys($export['tenants']));
        $acme = $export['tenants']['acme'];
        self::assertSame([$a], $acme['company.owner']);
        self::assertContains($c, $acme['contact.owner'], 'the owner change');
        self::assertContains($m1, $acme['contact.owner']);
        foreach (['company.created_by', 'note.author', 'activity.actor', 'activity.owner', 'consent.recorded_by', 'host_link.created_by', 'role.created_by', 'import.created_by', 'merge.actor', 'merge.owner', 'settings.updated_by'] as $kind) {
            self::assertNotEmpty($acme[$kind] ?? [], $kind);
        }
        self::assertGreaterThan(ActorPersonalData::BATCH, count($acme['activity.actor']), 'more than one batch');
        self::assertSame([$g], $export['tenants']['globex']['company.created_by']);
        $leoBefore = $data->exportActor('leo');
        $total     = array_sum(array_map(static fn (array $kinds): int => array_sum(array_map(count(...), $kinds)), $export['tenants']));
        $versionA  = $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$a]);
        $records   = $this->int('SELECT COUNT(*) FROM nowo_crm_company') + $this->int('SELECT COUNT(*) FROM nowo_crm_contact');

        $seen = new class {
            /** @var list<ActorAnonymised> */
            public array $events = [];

            /** @var list<bool> */
            public array $inTransaction = [];
        };
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CrmKitEvents::ACTOR_ANONYMISED, function (ActorAnonymised $event) use ($seen): void {
            $seen->events[]        = $event;
            $seen->inTransaction[] = $this->connection()->isTransactionActive();
        });

        self::assertSame($total, $data->anonymiseActor('ana'));
        self::assertSame([], $data->exportActor('ana')['tenants'], 'nothing references ana any more');
        self::assertSame($leoBefore, $data->exportActor('leo'), 'other people untouched');
        self::assertSame($records, $this->int('SELECT COUNT(*) FROM nowo_crm_company') + $this->int('SELECT COUNT(*) FROM nowo_crm_contact'), 'records kept');
        self::assertSame(ActorPersonalData::PLACEHOLDER, $this->text('SELECT owner_id FROM nowo_crm_company WHERE id = ?', [$a]));
        self::assertSame('leo', $this->text('SELECT owner_id FROM nowo_crm_company WHERE id = ?', [$l]));
        self::assertSame('by ana', $this->text('SELECT body FROM nowo_crm_note WHERE author_id = ?', [ActorPersonalData::PLACEHOLDER]), 'the note stays');
        self::assertGreaterThan($versionA, $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$a]), 'a form opened before is stale');
        self::assertSame(['acme', 'globex'], array_map(static fn (ActorAnonymised $e): string => $e->tenant->value, $seen->events));
        self::assertSame($total, array_sum(array_map(static fn (ActorAnonymised $e): int => $e->references, $seen->events)));
        self::assertSame([false, false], $seen->inTransaction, 'after the commits');
        self::assertCount(2, array_filter($this->recorder()->actions(), static fn (string $a): bool => $a === 'crm.actor.anonymised'));

        // Idempotent: nothing left, nothing announced.
        self::assertSame(0, $data->anonymiseActor('ana'));
        self::assertCount(2, $seen->events);
        try {
            $data->anonymiseActor(ActorPersonalData::PLACEHOLDER);
            self::fail('the placeholder itself');
        } catch (InvalidInputException) {
        }

        // The stale form fails instead of writing the old owner back.
        $this->ctx()->tenant = 'acme';
        $this->everything('acme', 'leo');
        try {
            $this->records()->update($a, new RecordInput(SubjectType::Company, ['city' => 'Paris']), $versionA);
            self::fail('lost update');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }

        // The shared entity manager is never cleared or reset.
        self::assertTrue($em->isOpen());
        self::assertSame($em, $this->em());
        self::assertTrue($em->contains($hostNote), 'the host entity stays managed');
    }

    private function everything(string $tenant, string $actor): void
    {
        $this->ctx()->actor = $actor;
        $this->ctx()->grant($tenant, $actor, 'view', 'create', 'edit', 'delete', 'manage', 'erase', 'export', 'import');
    }

    private function signIn(string $user): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, 'x', ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
    }
}
