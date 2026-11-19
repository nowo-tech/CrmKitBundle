<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\CrmResource;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\BinaryCollation;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ParameterFlattener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordAccessCheckEvent;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordVisibilityEvent;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\CrmConflictFormatter;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\DomainExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredFeatureGate;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredPlanLimits;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\PsrLogAuditSink;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SingleTenantResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SymfonyUuidGenerator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ConfigurableCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\LegacyCrmAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ScopedRecordVisibility;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\SecurityCrmAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\TenantAwareCrmKitAccessCheckerInterface;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

/**
 * The default ports, the HTTP listeners and the small infrastructure helpers.
 */
final class PortsAndHttpTest extends TestCase
{
    public function testDefaultPorts(): void
    {
        $tenant = new TenantRef('acme');
        self::assertSame('default', (new SingleTenantResolver())->current()->value);
        self::assertFalse((new ConfiguredFeatureGate(false))->isEnabled($tenant));
        self::assertSame(5, (new ConfiguredPlanLimits(['contacts' => 5]))->limit($tenant, CrmResource::Contacts));
        self::assertNull((new ConfiguredPlanLimits())->limit($tenant, CrmResource::Companies));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7/', (new SymfonyUuidGenerator())->new());
        $logger = new class extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = [$message, $context];
            }
        };
        (new PsrLogAuditSink($logger))->record(new AuditEntry($tenant, new ActorRef('ana'), 'crm.x', 'company', 'id', ['n' => 1], new DateTimeImmutable()));
        (new PsrLogAuditSink())->record(new AuditEntry($tenant, null, 'crm.y', null, null, [], new DateTimeImmutable()));
        self::assertSame(['CRM audit: crm.x', ['tenant' => 'acme', 'actor' => 'ana', 'subject_type' => 'company', 'subject_id' => 'id', 'n' => 1]], $logger->lines[0]);
    }

    public function testAccessCheckersAndSecurityDirectory(): void
    {
        $tenant = new TenantRef('acme');
        $tokens = new TokenStorage();
        $auth   = new class implements AuthorizationCheckerInterface {
            public bool $fail = false;

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                if ($this->fail) {
                    throw new RuntimeException('voter down');
                }

                return $attribute === 'ROLE_CRM';
            }
        };
        // The default area checker: any role of the area's list; an empty list means no role check; fail closed.
        $areas = new ConfigurableCrmKitAccessChecker($auth, ['ROLE_OTHER', 'ROLE_CRM'], ['ROLE_ADMIN'], ['ROLE_ADMIN'], ['ROLE_CRM'], ['ROLE_ADMIN'], ['ROLE_CRM'], []);
        $user  = new InMemoryUser('ana', null, ['ROLE_CRM']);
        self::assertSame([true, false, false, false, false, true, false, false, true, true], [$areas->canAccess($user), $areas->canCreate($user), $areas->canEdit($user), $areas->canDelete($user), $areas->canImport($user), $areas->canExport($user), $areas->canManageSettings($user), $areas->canErase($user), $areas->canViewAmounts($user), $areas->canSendCampaigns($user)]);
        self::assertFalse($areas->canAccess(null), 'nobody signed in');
        self::assertFalse((new ConfigurableCrmKitAccessChecker())->canAccess($user), 'no SecurityBundle: closed');
        self::assertTrue((new ConfigurableCrmKitAccessChecker(null, []))->canAccess(null), 'an empty list asks no role');
        $all = new AllowAllCrmKitAccessChecker();
        self::assertSame([true, true, true, true, true, true, true, true, true, true], [$all->canAccess(null), $all->canCreate(null), $all->canEdit(null), $all->canDelete(null), $all->canImport(null), $all->canExport(null), $all->canManageSettings(null), $all->canErase(null), $all->canViewAmounts(null), $all->canSendCampaigns(null)]);

        // The port adapter: no token ⇒ no actor; a user ⇒ the area of each permission, then the record decision.
        $events   = new EventDispatcher();
        $scoped   = new ScopedRecordVisibility($areas, $tokens, $events, true);
        $resource = new CrmResourceAccessChecker($events, $scoped);
        $checker  = new SecurityCrmAccessChecker($areas, $resource, $tokens);
        self::assertNull($checker->currentActor());
        self::assertFalse($checker->isGranted(CrmPermission::View, $tenant));
        self::assertSame('anonymous', (new SecurityCrmAccessChecker($all, $resource, $tokens, true))->currentActor()?->value);
        self::assertTrue((new SecurityCrmAccessChecker($all, $resource, null, true))->isGranted(CrmPermission::Erase, $tenant));
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_CRM']));
        self::assertSame('ana', (new SecurityCrmAccessChecker($areas, $resource, $tokens))->currentActor()?->value);
        $map = static fn (SecurityCrmAccessChecker $c): array => array_map(static fn (CrmPermission $p): bool => $c->isGranted($p, $tenant), CrmPermission::cases());
        // view edit manage amounts campaigns create delete import export erase
        self::assertSame([true, false, false, true, true, false, false, false, true, false], $map($checker));
        self::assertSame([true, false, false, true, true, false, false, false, true, false], $map(new SecurityCrmAccessChecker(new class implements CrmKitAccessCheckerInterface {
            public function canAccess(?object $user): bool
            {
                return true;
            }

            public function canCreate(?object $user): bool
            {
                return false;
            }

            public function canEdit(?object $user): bool
            {
                return false;
            }

            public function canDelete(?object $user): bool
            {
                return false;
            }

            public function canImport(?object $user): bool
            {
                return false;
            }

            public function canExport(?object $user): bool
            {
                return true;
            }

            public function canManageSettings(?object $user): bool
            {
                return false;
            }

            public function canErase(?object $user): bool
            {
                return false;
            }

            public function canViewAmounts(?object $user): bool
            {
                return true;
            }

            public function canSendCampaigns(?object $user): bool
            {
                return true;
            }
        }, $resource, $tokens)), 'a checker that is not tenant aware');

        // Records: with scoping, the owner's (or an ownerless) record is open, another person's is not, unless a
        // listener grants it; a deny wins over a grant.
        $mine   = SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-000000000001', 'ana');
        $theirs = SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-000000000002', 'leo');
        $free   = SubjectRef::of(SubjectType::Contact, '01900000-0000-7000-8000-000000000003');
        self::assertSame([true, false, true], [$checker->isGranted(CrmPermission::View, $tenant, $mine), $checker->isGranted(CrmPermission::View, $tenant, $theirs), $checker->isGranted(CrmPermission::View, $tenant, $free)]);
        $seen = [];
        $events->addListener(CrmKitEvents::RECORD_ACCESS_CHECK, static function (RecordAccessCheckEvent $e) use (&$seen): void {
            $seen[] = [$e->getUser() instanceof InMemoryUser, $e->getActorId(), $e->getTenantKey(), $e->getSubjectType(), $e->getOwnerId(), $e->getAction(), $e->isDefaultGranted(), substr($e->getSubjectId(), -1)];
            if ($e->getOwnerId() === 'leo') {
                $e->grant();
            }
            if ($e->getSubjectType() === 'contact') {
                $e->deny();
                $e->grant();
            }
        });
        self::assertTrue($checker->isGranted(CrmPermission::View, $tenant, $theirs), 'a listener grants');
        self::assertFalse($checker->isGranted(CrmPermission::View, $tenant, $free), 'a deny wins');
        self::assertSame([[true, 'ana', 'acme', 'company', 'leo', 'view', false, '2'], [true, 'ana', 'acme', 'contact', null, 'view', true, '3']], $seen);
        $denied = new RecordAccessCheckEvent(null, null, 'acme', 'company', 'x', null, 'edit', true);
        $denied->deny();
        self::assertSame([true, false], [$denied->isDenied(), $denied->isGranted()]);
        // The visibility of lists and the default of one record: the settings area sees everything (the host's checker
        // when configured), a listener adds owners or opens all, no scoping shares the tenant's records.
        $managers = new ConfigurableCrmKitAccessChecker($auth, [], [], ['ROLE_CRM']);
        self::assertTrue((new CrmResourceAccessChecker(null, new ScopedRecordVisibility($managers, $tokens, null, true)))->isGranted($user, new ActorRef('ana'), $tenant, CrmPermission::Edit, $theirs), 'settings_roles see every record');
        self::assertNull((new ScopedRecordVisibility(new class implements TenantAwareCrmKitAccessCheckerInterface {
            public function canAccess(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canCreate(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canEdit(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canDelete(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canImport(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canExport(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canManageSettings(?object $user, ?string $tenantKey = null): bool
            {
                return $tenantKey === 'acme';
            }

            public function canErase(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canViewAmounts(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canSendCampaigns(?object $user, ?string $tenantKey = null): bool
            {
                return true;
            }
        }, $tokens, null, true))->visibleOwners($tenant, new ActorRef('ana')), 'a tenant-aware host checker decides the bypass');
        self::assertSame(['ana'], (new ScopedRecordVisibility($areas, null, null, true))->visibleOwners($tenant, new ActorRef('ana')));
        self::assertNull((new ScopedRecordVisibility($areas))->visibleOwners($tenant, new ActorRef('ana')), 'no scoping');
        self::assertFalse((new CrmResourceAccessChecker(null, $scoped))->isGranted(null, null, $tenant, CrmPermission::Edit, $theirs), 'nobody acting');
        self::assertTrue((new CrmResourceAccessChecker())->isGranted(null, null, $tenant, CrmPermission::Edit, $theirs), 'no visibility: the tenant\'s records are shared');
        $visibility = new EventDispatcher();
        $visibility->addListener(CrmKitEvents::RECORD_VISIBILITY, static function (RecordVisibilityEvent $e): void {
            self::assertSame(['ana', 'acme'], [$e->getActorId(), $e->getTenantKey()]);
            self::assertInstanceOf(InMemoryUser::class, $e->getUser());
            $e->addOwner('leo');
            $e->addOwner('leo');
        });
        self::assertSame(['ana', 'leo'], (new ScopedRecordVisibility($areas, $tokens, $visibility, true))->visibleOwners($tenant, new ActorRef('ana')));
        self::assertTrue((new CrmResourceAccessChecker(null, new ScopedRecordVisibility($areas, $tokens, $visibility, true)))->isGranted($user, new ActorRef('ana'), $tenant, CrmPermission::Edit, $theirs), 'a team owner added');
        $visibility->addListener(CrmKitEvents::RECORD_VISIBILITY, static function (RecordVisibilityEvent $e): void {
            $e->allowAll();
        });
        self::assertNull((new ScopedRecordVisibility($areas, $tokens, $visibility, true))->visibleOwners($tenant, new ActorRef('ana')));
        $auth->fail = true;
        self::assertFalse($checker->isGranted(CrmPermission::View, $tenant));
        $auth->fail = false;

        // The deprecated 0.1.0 port: the new permissions reach it as the ones it knew.
        $inner = new class implements CrmAccessCheckerInterface {
            /** @var list<string> */
            public array $asked = [];

            public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
            {
                $this->asked[] = $permission->value;

                return true;
            }

            public function currentActor(): ?ActorRef
            {
                return $this->asked === [] ? null : new ActorRef('ana');
            }
        };
        $legacy = new LegacyCrmAccessChecker($inner);
        self::assertNull($legacy->currentActor());
        foreach (CrmPermission::cases() as $permission) {
            $legacy->isGranted($permission, $tenant);
        }
        self::assertSame(['view', 'edit', 'manage', 'amounts_view', 'campaigns_send', 'edit', 'edit', 'edit', 'manage', 'manage'], $inner->asked);
        self::assertSame('ana', (new LegacyCrmAccessChecker($inner))->currentActor()?->value);
        $directory = new SecurityActorDirectory($tokens);
        self::assertSame('ana', $directory->actor($tenant, new ActorRef('ana'))?->name);
        self::assertNull($directory->actor($tenant, new ActorRef('Ana')));
        self::assertCount(1, $directory->owners($tenant));
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('bad ', null), 'main'));
        self::assertNull($checker->currentActor());
        self::assertSame([], $directory->owners($tenant));
        self::assertSame([], (new SecurityActorDirectory())->owners($tenant));
    }

    public function testHelpers(): void
    {
        self::assertSame(BinaryCollation::MARIADB, BinaryCollation::for(new MariaDBPlatform()));
        self::assertSame(BinaryCollation::MYSQL_8, BinaryCollation::for(new MySQL80Platform()));
        self::assertSame(BinaryCollation::FALLBACK, BinaryCollation::for(new MySQLPlatform()));
        self::assertNull(BinaryCollation::for(new PostgreSQLPlatform()));
        $type     = new UtcDateTimeImmutableType();
        $platform = new SQLitePlatform();
        self::assertSame('2026-11-18 09:00:00', $type->convertToDatabaseValue(new DateTimeImmutable('2026-11-18 10:00:00+01:00'), $platform));
        self::assertSame('UTC', $type->convertToPHPValue('2026-11-18 09:00:00', $platform)?->getTimezone()->getName());
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertSame('UTC', $type->convertToPHPValue(new DateTimeImmutable('2026-11-18 10:00:00+01:00'), $platform)?->getTimezone()->getName());
        self::assertSame('2026-11-18', $type->convertToPHPValue('2026-11-18', $platform)?->format('Y-m-d'));
        self::assertSame(UtcDateTimeImmutableType::NAME, $type->getName());
        self::assertSame(['p.a.b' => 1, 'p.l' => [1, 2], 'p.e' => []], ParameterFlattener::flatten('p', ['a' => ['b' => 1], 'l' => [1, 2], 'e' => []]));
        self::assertSame(5, ServiceDefinitions::syncChunks(500, 100));
        self::assertSame(1, ServiceDefinitions::syncChunks(10, 100));
        self::assertTrue((new UiRouteLoader())->supports('.', UiRouteLoader::TYPE));
        self::assertCount(0, (new UiRouteLoader(false))->load('.'));
        self::assertNotNull((new UiRouteLoader(true, '/'))->load('.')->get('nowo_crm_kit_home'));
    }

    public function testTheConflictFormatterAndFallback(): void
    {
        $request = $this->request(json: true);
        $context = new ConflictResponseContext(409, 1, 'concurrency_conflict', 'm', 3, new RuntimeException());
        $answer  = (new CrmConflictFormatter())->format($request, $context);
        self::assertSame('{"error":"concurrency_conflict","code":"CONFLICT_001"}', $answer?->getContent());
        self::assertNull((new CrmConflictFormatter())->format($this->request(), $context));
        self::assertNull((new CrmConflictFormatter())->format($this->request(json: true, crm: false), $context));

        $listener = new ConflictFallbackListener();
        $conflict = new class('busy') extends RuntimeException implements LockConflictExceptionInterface {
        };
        $event = $this->event($this->request(json: true), new RuntimeException('wrapped', 0, $conflict));
        $listener->onKernelException($event);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
        self::assertSame('CONFLICT_001', $event->getResponse()->headers->get('X-Conflict-Code'));
        self::assertSame('1', $event->getResponse()->headers->get('Retry-After'));
        $html = $this->event($this->request(), $conflict);
        $listener->onKernelException($html);
        self::assertStringContainsString('Try again', (string) $html->getResponse()?->getContent());
        $ledger  = new CommitLedger();
        $request = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();
        $partial = $this->event($request, $conflict);
        $listener->onKernelException($partial);
        self::assertSame('CONFLICT_002', $partial->getResponse()?->headers->get('X-Conflict-Code'));
        self::assertNull($partial->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('Reload', (string) $partial->getResponse()->getContent());
        foreach ([$this->event($this->request(crm: false), $conflict), $this->event($this->request(), new RuntimeException('other'))] as $ignored) {
            $listener->onKernelException($ignored);
            self::assertNull($ignored->getResponse());
        }
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure(new ConcurrencyConflict(3, TransientFailureKind::Deadlock, null, new RuntimeException())));
    }

    public function testTheDomainExceptionListener(): void
    {
        $listener = new DomainExceptionListener();
        $cases    = [
            [new NotAvailableException(), null, NotFoundHttpException::class],
            [new AccessDeniedException(), null, AccessDeniedHttpException::class],
        ];
        foreach ($cases as [$failure, , $mapped]) {
            $event = $this->event($this->request(), $failure);
            $listener->onKernelException($event);
            self::assertInstanceOf($mapped, $event->getThrowable());
        }
        foreach ([[true, '{"error":"crm_record_stale"}'], [false, 'crm_record_stale']] as [$json, $body]) {
            $event = $this->event($this->request(json: $json), new ConflictException(ConflictException::STALE));
            $listener->onKernelException($event);
            self::assertSame(409, $event->getResponse()?->getStatusCode());
            self::assertSame($body, $event->getResponse()->getContent());
            self::assertNull($event->getResponse()->headers->get('X-Conflict-Code'));
        }
        foreach ([[true, InvalidInputException::field('email', 'error.email'), 422], [false, new ImportFileException('import.error.empty'), 400]] as [$json, $failure, $status]) {
            $event = $this->event($this->request(json: $json), $failure);
            $listener->onKernelException($event);
            self::assertSame($status, $event->getResponse()?->getStatusCode());
        }
        foreach ([$this->event($this->request(crm: false), new NotAvailableException()), $this->event($this->request(), new RuntimeException())] as $ignored) {
            $listener->onKernelException($ignored);
            self::assertNull($ignored->getResponse());
            self::assertNotInstanceOf(NotFoundHttpException::class, $ignored->getThrowable());
        }
    }

    private function request(bool $json = false, bool $crm = true): Request
    {
        $request = Request::create('/crm/companies');
        if ($json) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }
        if ($crm) {
            $request->attributes->set(UiRouteLoader::ROUTE_ATTRIBUTE, true);
        }

        return $request;
    }

    private function event(Request $request, Throwable $failure): ExceptionEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
