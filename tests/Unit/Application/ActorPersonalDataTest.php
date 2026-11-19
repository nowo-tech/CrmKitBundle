<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

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

use function array_map;

/**
 * Audit VD-3: when a later batch fails, the batches already committed are still audited and announced, then the
 * failure is rethrown.
 */
final class ActorPersonalDataTest extends TestCase
{
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
