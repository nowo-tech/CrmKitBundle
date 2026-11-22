<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\ActorPersonalData;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Read\NoteHit;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Service\CrmActorPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\QueryLog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_keys;
use function array_map;
use function count;
use function mb_strlen;
use function str_repeat;

/**
 * Note search and batched notes (0.5.0, Beacon spec 251): excerpts and order, literal LIKE wildcards, hidden records
 * never shown (not even by filling the limit), look-alike tenants, erased / merged / anonymised data, owner scoping, and
 * a fixed number of queries for any number of records.
 */
final class NotesSearchTest extends CrmTestCase
{
    public function testSearchReturnsHitsWithSubjectsAndExcerpts(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'Lovelace');
        $n1   = $this->notes()->add(SubjectType::Company, $acme, 'Renewal call: the budget is approved.');
        $n2   = $this->notes()->add(SubjectType::Contact, $ada, str_repeat('x', 300) . ' BUDGET talk ' . str_repeat('y', 300), true);
        $this->notes()->add(SubjectType::Contact, $ada, 'Unrelated');

        $hits = $this->reader()->searchNotes('  budget ', 10);
        self::assertSame([$n2, $n1], array_map(static fn (NoteHit $h): string => $h->note->id, $hits), 'newest first');
        self::assertSame([SubjectType::Contact, $ada], [$hits[0]->subjectType, $hits[0]->subjectId]);
        self::assertSame([SubjectType::Company, $acme], [$hits[1]->subjectType, $hits[1]->subjectId]);
        self::assertSame('Renewal call: the budget is approved.', $hits[1]->excerpt, 'a short note is its own excerpt');
        self::assertStringStartsWith('…', $hits[0]->excerpt);
        self::assertStringEndsWith('…', $hits[0]->excerpt);
        self::assertStringContainsString('BUDGET talk', $hits[0]->excerpt);
        self::assertSame(NoteHit::EXCERPT_LENGTH + 2, mb_strlen($hits[0]->excerpt));
        self::assertTrue($hits[0]->note->pinned);
        self::assertSame('Ana', $hits[0]->note->authorName);

        self::assertCount(1, $this->reader()->searchNotes('budget', 1), 'limit');
        self::assertCount(1, $this->reader()->searchNotes('budget', 0), 'a limit under 1 is 1');
        self::assertSame([], $this->reader()->searchNotes('b', 10), 'too short');
        self::assertSame([], $this->reader()->searchNotes('   ', 10));
        $long = $this->notes()->add(SubjectType::Company, $acme, str_repeat('q', 200));
        self::assertSame([$long], array_map(static fn (NoteHit $h): string => $h->note->id, $this->reader()->searchNotes(str_repeat('q', 250), 10)), 'cut to 200 characters');

        // A note of a trashed record is not searched; the contact without a matching note never appears.
        $this->records()->trash(SubjectType::Company, $acme, $this->reader()->record(SubjectType::Company, $acme)->version);
        self::assertSame([$n2], array_map(static fn (NoteHit $h): string => $h->note->id, $this->reader()->searchNotes('budget', 10)));

        // view is required, as for every list.
        $this->actAs('leo');
        $this->expectException(AccessDeniedException::class);
        $this->reader()->searchNotes('budget', 10);
    }

    public function testLikeWildcardsAndEscapeCharactersAreLiteral(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $pct  = $this->notes()->add(SubjectType::Company, $acme, 'We are 100% sure');
        $this->notes()->add(SubjectType::Company, $acme, 'We are 1000 sure');
        $under = $this->notes()->add(SubjectType::Company, $acme, 'file a_b.txt');
        $this->notes()->add(SubjectType::Company, $acme, 'file axb.txt');
        $slash = $this->notes()->add(SubjectType::Company, $acme, 'path C:\\temp\\x');
        $this->notes()->add(SubjectType::Company, $acme, 'path C:temp');
        $bang = $this->notes()->add(SubjectType::Company, $acme, 'wow!% done');
        $this->notes()->add(SubjectType::Company, $acme, 'wow done');

        self::assertSame([$pct], $this->found('0%'), '% is literal');
        self::assertSame([$under], $this->found('a_b'), '_ is literal');
        self::assertSame([$slash], $this->found(':\\t'), '\\ is literal');
        self::assertSame([$bang], $this->found('!%'), 'the escape character is literal');
        self::assertSame([], $this->found('%%'));
        self::assertSame([$under], $this->found('A_B'), 'case-insensitive');
    }

    public function testHiddenRecordsNeverAppearNotEvenWhenFillingTheLimit(): void
    {
        $this->boot();
        $this->admin();
        $visible = $this->contact('Ada', 'Visible');
        $hidden  = $this->company('Hidden');
        $old     = $this->notes()->add(SubjectType::Contact, $visible, 'secret plan A');
        // 60 newer matches on a record the actor may not view: more than one round of the search.
        for ($i = 0; $i < 60; ++$i) {
            $this->notes()->add(SubjectType::Company, $hidden, 'secret plan ' . $i);
        }
        $newest              = $this->notes()->add(SubjectType::Contact, $visible, 'secret plan B');
        $this->ctx()->denied = ['view:' . $hidden];

        self::assertSame([$newest], $this->found('secret', 1));
        self::assertSame([$newest, $old], $this->found('secret', 50), 'the older visible note is reached past the hidden ones');
        self::assertSame([$newest, $old], $this->found('plan', 2));
        self::assertSame([], $this->found('plan 1'), 'no excerpt of the hidden record');
        self::assertSame([], $this->reader()->notesOf([SubjectRef::of(SubjectType::Company, $hidden)]), 'nor its notes');

        // The single-record path refuses it the same way.
        $this->expectException(AccessDeniedException::class);
        $this->reader()->notes(SubjectType::Company, $hidden);
    }

    public function testOwnerScopingAppliesAsInLists(): void
    {
        $this->boot(['access_checker' => null, 'security' => ['access_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'manage_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'export_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'resource_scoping' => true]]);
        $this->signIn('root');
        $anas   = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ann', 'last_name' => 'Owned'], 'ana'));
        $leos   = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Leo Co'], 'leo'));
        $shared = $this->company('Shared');
        $a      = $this->notes()->add(SubjectType::Contact, $anas, 'quarterly review');
        $l      = $this->notes()->add(SubjectType::Company, $leos, 'quarterly review');
        $s      = $this->notes()->add(SubjectType::Company, $shared, 'quarterly review');
        self::assertSame([$s, $l, $a], $this->found('quarterly'));

        $this->signIn('leo');
        self::assertSame([$s, $l], $this->found('quarterly'), 'only his own records and ownerless ones');
        $batch = $this->reader()->notesOf([SubjectRef::of(SubjectType::Contact, $anas), SubjectRef::of(SubjectType::Company, $leos), SubjectRef::of(SubjectType::Company, $shared)]);
        self::assertSame(['company:' . $leos, 'company:' . $shared], array_keys($batch));

        // No visible owner at all: ownerless records only.
        self::assertSame([$s], array_map(static fn (mixed $n): string => $n instanceof Note ? $n->getId() : '', $this->service(CrmStoreInterface::class)->searchNotes(new TenantRef('acme'), 'quarterly', 10, null, [])));
    }

    public function testLookAlikeTenantsNeverMix(): void
    {
        $this->boot();
        $this->admin();
        $mine   = $this->company('Acme');
        $note   = $this->notes()->add(SubjectType::Company, $mine, 'invoice overdue');
        $others = [];
        foreach (['Acme', 'ACME', 'acme2'] as $tenant) {
            $this->ctx()->tenant = $tenant;
            $this->actAs('ana', 'view', 'edit', 'manage');
            $id       = $this->company('Twin');
            $others[] = $id;
            $this->notes()->add(SubjectType::Company, $id, 'invoice overdue');
        }
        $this->ctx()->tenant = 'acme';
        $this->ctx()->actor  = 'ana';

        self::assertSame([$note], $this->found('invoice'));
        $refs = array_map(static fn (string $id): SubjectRef => SubjectRef::of(SubjectType::Company, $id), [$mine, ...$others]);
        self::assertSame(['company:' . $mine], array_keys($this->reader()->notesOf($refs)));
        self::assertSame([$note], array_map(static fn (mixed $n): string => $n instanceof Note ? $n->getId() : '', $this->service(CrmStoreInterface::class)->notesOfMany(new TenantRef('acme'), ['company' => [$mine, ...$others]], 5)));
    }

    public function testErasedMergedAndAnonymisedData(): void
    {
        $this->boot();
        $this->actAs('ana', 'view', 'edit', 'manage');
        $ada = $this->contact('Ada', 'Erased');
        $bob = $this->contact('Bob', 'Kept');
        $this->notes()->add(SubjectType::Contact, $ada, 'gdpr target note');
        $this->ctx()->actor = 'leo';
        $this->ctx()->grant('acme', 'leo', 'view', 'edit');
        $byLeo              = $this->notes()->add(SubjectType::Contact, $bob, 'gdpr note by leo');
        $this->ctx()->actor = 'ana';

        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        self::assertSame([$byLeo], $this->found('gdpr'), 'an erased contact\'s notes are gone');
        self::assertSame([], $this->reader()->notesOf([SubjectRef::of(SubjectType::Contact, $ada)]), 'an erased contact is left out');

        // An anonymised author shows as on the record's page: the placeholder id, no name.
        $this->service(CrmActorPersonalDataInterface::class)->anonymiseActor('leo');
        $hit = $this->reader()->searchNotes('gdpr', 5)[0];
        self::assertSame([ActorPersonalData::PLACEHOLDER, null], [$hit->note->authorId, $hit->note->authorName]);
        self::assertEquals($this->reader()->notes(SubjectType::Contact, $bob), $this->reader()->notesOf([SubjectRef::of(SubjectType::Contact, $bob)])['contact:' . $bob]);

        // A merged record's notes move to the survivor; the tombstone is left out.
        $a     = $this->company('Merge A');
        $b     = $this->company('Merge B');
        $moved = $this->notes()->add(SubjectType::Company, $b, 'merge me');
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Company, $a, $b, [], $this->reader()->record(SubjectType::Company, $a)->version, $this->reader()->record(SubjectType::Company, $b)->version);
        $batch = $this->reader()->notesOf([SubjectRef::of(SubjectType::Company, $a), SubjectRef::of(SubjectType::Company, $b)]);
        self::assertSame(['company:' . $a], array_keys($batch));
        self::assertSame([$moved], array_map(static fn (NoteView $n): string => $n->id, $batch['company:' . $a]));
        $hits = $this->reader()->searchNotes('merge me', 5);
        self::assertSame([$a], array_map(static fn (NoteHit $h): string => $h->subjectId, $hits));
    }

    public function testNotesOfIsBatchedWithoutNPlusOne(): void
    {
        $this->boot();
        $this->admin();
        $subjects = [];
        $expected = [];
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $id         = $this->company($name);
            $subjects[] = SubjectRef::of(SubjectType::Company, $id);
            $notes      = [];
            for ($i = 0; $i < 4; ++$i) {
                $notes[] = $this->notes()->add(SubjectType::Company, $id, $name . ' note ' . $i, $i === 0);
            }
            $expected['company:' . $id] = [$notes[0], $notes[3], $notes[2]];
        }
        foreach (['Ann', 'Bea'] as $name) {
            $id                         = $this->contact($name, 'Batch');
            $subjects[]                 = SubjectRef::of(SubjectType::Contact, $id);
            $expected['contact:' . $id] = [];
        }
        $noteOfContact                 = $this->notes()->add(SubjectType::Contact, $subjects[3]->id->value, 'only one');
        $expected[$subjects[3]->key()] = [$noteOfContact];
        $trashed                       = $this->company('Trashed');
        $trashedNote                   = $this->notes()->add(SubjectType::Company, $trashed, 'still shown on its page');
        $this->records()->trash(SubjectType::Company, $trashed, $this->reader()->record(SubjectType::Company, $trashed)->version);
        $expected['company:' . $trashed] = [$trashedNote];

        $queries = $this->queries();
        $queries->take();
        $two = $this->reader()->notesOf([$subjects[0], $subjects[3]], 3);
        $few = count($queries->take());
        self::assertSame([$subjects[0]->key(), $subjects[3]->key()], array_keys($two));

        $all = [...$subjects, SubjectRef::of(SubjectType::Company, $trashed), SubjectRef::of(SubjectType::Deal, $subjects[0]->id->value), SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-000000000000'), $subjects[0]];
        $queries->take();
        $batch = $this->reader()->notesOf($all, 3);
        self::assertCount($few, $queries->take(), 'the same queries for two records or seven');
        self::assertLessThanOrEqual(6, $few);
        self::assertSame($expected, array_map(static fn (array $notes): array => array_map(static fn (NoteView $n): string => $n->id, $notes), $batch), 'pinned first, then newest; unknown and deal refs left out');

        self::assertCount(1, $this->reader()->notesOf([$subjects[0]], 0)['company:' . $subjects[0]->id->value], 'per subject clamped to 1');
        self::assertCount(4, $this->reader()->notesOf([$subjects[0]], 500)['company:' . $subjects[0]->id->value]);
        self::assertSame([], $this->reader()->notesOf([]));
        self::assertSame([], $this->service(CrmStoreInterface::class)->notesOfMany(new TenantRef('acme'), [], 5));
        self::assertSame([], $this->service(CrmStoreInterface::class)->notesOfMany(new TenantRef('acme'), ['contact' => [$subjects[4]->id->value]], 5));
    }

    public function testSearchQueriesAreBounded(): void
    {
        $this->boot();
        $this->admin();
        $hidden = $this->company('Hidden');
        for ($i = 0; $i < CrmReader::NOTE_SEARCH_LIMIT * 11; ++$i) {
            $this->notes()->add(SubjectType::Company, $hidden, 'needle ' . $i);
        }
        $this->ctx()->denied = ['view:' . $hidden];
        $queries             = $this->queries();
        $queries->take();
        self::assertSame([], $this->found('needle', 50));
        self::assertLessThanOrEqual(10 * 2 + 2, count($queries->take()), 'at most ten rounds of a note query and a record query');
    }

    /**
     * @return list<string>
     */
    private function found(string $text, int $limit = 20): array
    {
        return array_map(static fn (NoteHit $h): string => $h->note->id, $this->reader()->searchNotes($text, $limit));
    }

    private function reader(): CrmReaderInterface
    {
        return $this->service(CrmReaderInterface::class);
    }

    private function notes(): NoteServiceInterface
    {
        return $this->service(NoteServiceInterface::class);
    }

    private function queries(): QueryLog
    {
        $log = $this->kernel->getContainer()->get('test.queries');
        self::assertInstanceOf(QueryLog::class, $log);

        return $log;
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
