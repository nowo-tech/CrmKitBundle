<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

use LogicException;
use Nowo\CrmKitBundle\Application\ActorPersonalData;
use Nowo\CrmKitBundle\Application\Port\CrmActorDataStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Event\ActorAnonymised;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Tests\Fixtures\TestRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Audit VD-3: when a later batch fails, the batches already committed are still audited and announced, then the
 * failure is rethrown.
 */
final class ActorPersonalDataTest extends TestCase
{
    public function testOneFailingReleaseNeverLosesTheOtherTenantsAndTheOriginalFailureWins(): void
    {
        $cause    = new LogicException('deadlock');
        $recorder = new TestRecorder();
        // The audit of acme fails; globex must still be audited and announced.
        $recorder->onRecord = static function (AuditEntry $entry): void {
            if ($entry->tenant->value === 'acme') {
                throw new RuntimeException('audit sink down');
            }
        };
        $seen = new class {
            /** @var list<string> */
            public array $tenants = [];
        };
        $events = new EventDispatcher();
        $events->addListener(ActorAnonymised::class, static function (ActorAnonymised $e) use ($seen): void {
            $seen->tenants[] = $e->tenant->value;
        });
        $service = new ActorPersonalData($this->store(new RuntimeException('lock wait timeout', 0, $cause)), $this->runner(), $recorder, new MockClock(), $events);
        try {
            $service->anonymiseActor('ana');
            self::fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('lock wait timeout', $e->getMessage(), 'the original failure');
            self::assertSame($cause, $e->getPrevious());
            self::assertSame('audit sink down', $cause->getPrevious()?->getMessage(), 'the release failure attached to its chain');
        }
        self::assertSame(['globex'], $seen->tenants);
        self::assertSame(['globex'], array_map(static fn (AuditEntry $a): string => $a->tenant->value, array_values(array_filter($recorder->audit, static fn (AuditEntry $a): bool => $a->tenant->value !== 'acme'))));

        // A complete run whose release fails for one tenant: the others are released, then the release failure surfaces.
        $seen->tenants = [];
        $complete      = new ActorPersonalData($this->store(null), $this->runner(), $recorder, new MockClock(), $events);
        try {
            $complete->anonymiseActor('ana');
            self::fail('the release failure was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('audit sink down', $e->getMessage());
        }
        self::assertSame(['globex'], $seen->tenants);
    }

    /**
     * A store with references in acme and globex; `$failure` is thrown from the third kind on (null: no failure).
     */
    private function store(?Throwable $failure): CrmActorDataStoreInterface
    {
        return new class($failure) implements CrmActorDataStoreInterface {
            public function __construct(private readonly ?Throwable $failure)
            {
            }

            public function scan(string $kind, string $actorId, ?string $after, int $limit, ?string $replacement = null): array
            {
                return match ($kind) {
                    'company.owner'      => ['rows' => [['tenant' => 'acme', 'key' => 'a1'], ['tenant' => 'globex', 'key' => 'g1']], 'next' => null],
                    'company.created_by' => ['rows' => [['tenant' => 'acme', 'key' => 'a2']], 'next' => null],
                    default              => $this->failure instanceof Throwable ? throw $this->failure : ['rows' => [], 'next' => null],
                };
            }
        };
    }

    private function runner(): TransactionRunnerInterface
    {
        return new class implements TransactionRunnerInterface {
            public function write(callable $work): mixed
            {
                return $work();
            }

            public function read(callable $work): mixed
            {
                return $work();
            }
        };
    }

    public function testCommittedBatchesAreReleasedBeforeAFailureIsRethrown(): void
    {
        $store = new class implements CrmActorDataStoreInterface {
            public function scan(string $kind, string $actorId, ?string $after, int $limit, ?string $replacement = null): array
            {
                return match ($kind) {
                    'company.owner'      => ['rows' => [['tenant' => 'acme', 'key' => 'a1'], ['tenant' => 'globex', 'key' => 'g1']], 'next' => null],
                    'company.created_by' => ['rows' => [['tenant' => 'acme', 'key' => 'a2']], 'next' => null],
                    default              => throw new RuntimeException('lock wait timeout'),
                };
            }
        };
        $runner = new class implements TransactionRunnerInterface {
            public function write(callable $work): mixed
            {
                return $work();
            }

            public function read(callable $work): mixed
            {
                return $work();
            }
        };
        $recorder = new TestRecorder();
        $events   = new EventDispatcher();
        $seen     = [];
        $events->addListener(ActorAnonymised::class, static function (ActorAnonymised $e) use (&$seen): void {
            $seen[] = $e->tenant->value . ':' . $e->references;
        });
        $service = new ActorPersonalData($store, $runner, $recorder, new MockClock(), $events);

        try {
            $service->anonymiseActor('ana');
            self::fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('lock wait timeout', $e->getMessage());
        }
        self::assertSame(['acme:2', 'globex:1'], $seen);
        self::assertSame([['references' => 2], ['references' => 1]], array_map(static fn (AuditEntry $a): array => $a->details, $recorder->audit));
    }
}
