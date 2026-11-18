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
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\BinaryCollation;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ParameterFlattener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\CrmConflictFormatter;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\DomainExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredFeatureGate;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredPlanLimits;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\PsrLogAuditSink;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\RoleMapAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SingleTenantResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SymfonyUuidGenerator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
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

    public function testRoleMapAndSecurityDirectory(): void
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
        $checker = new RoleMapAccessChecker(['view' => ['ROLE_OTHER', 'ROLE_CRM'], 'edit' => []], $auth, $tokens);
        self::assertNull($checker->currentActor());
        self::assertFalse($checker->isGranted(CrmPermission::View, $tenant));
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null, ['ROLE_CRM']), 'main', ['ROLE_CRM']));
        $checker = new RoleMapAccessChecker(['view' => ['ROLE_OTHER', 'ROLE_CRM'], 'edit' => []], $auth, $tokens);
        self::assertSame('ana', $checker->currentActor()?->value);
        self::assertTrue($checker->isGranted(CrmPermission::View, $tenant));
        self::assertFalse($checker->isGranted(CrmPermission::Edit, $tenant));
        self::assertFalse($checker->isGranted(CrmPermission::Manage, $tenant));
        $auth->fail = true;
        self::assertFalse($checker->isGranted(CrmPermission::View, $tenant));
        self::assertFalse((new RoleMapAccessChecker(['view' => ['ROLE_CRM']]))->isGranted(CrmPermission::View, $tenant));
        $directory = new SecurityActorDirectory($tokens);
        self::assertSame('ana', $directory->actor($tenant, new ActorRef('ana'))?->name);
        self::assertNull($directory->actor($tenant, new ActorRef('Ana')));
        self::assertCount(1, $directory->owners($tenant));
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('bad ', null), 'main'));
        self::assertNull((new RoleMapAccessChecker([], $auth, $tokens))->currentActor());
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
