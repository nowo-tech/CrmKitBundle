<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\SecretsRotateCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use SensitiveParameter;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function in_array;

/**
 * `nowo:crm-kit:secrets:rotate` never loses a token (0.10.1, audit ZAM-D2): a new seal that cannot be made, or that
 * does not open again to the same token, is never written, and the command exits with 1; invalid connections are
 * reported apart (ZAW-I5) and an unavailable key is logged without its cause's message (ZAW-I2).
 */
final class SecretsRotateCommandTest extends TestCase
{
    private const ID = '01890000-0000-7000-8000-000000000001';

    public function testASealThatFailsOrDoesNotOpenAgainIsNeverWritten(): void
    {
        foreach (['throws', 'differs', 'reopen-fails'] as $mode) {
            $connection = new HubSpotConnection(self::ID, 'acme', new HubSpotAccountId('1'), 'HubSpot 1', '5678', new SealedSecret('old', 'b2xk'), [], new DateTimeImmutable('2026-11-23'), 'ana', new DateTimeImmutable('2026-11-23'));
            $store      = $this->createMock(HubSpotStoreInterface::class);
            $store->method('connections')->willReturnOnConsecutiveCalls([['tenant' => 'acme', 'id' => self::ID]], []);
            $store->method('lockConnection')->willReturn($connection);
            $store->expects(self::never())->method('invalidate');
            $tester = new CommandTester(new SecretsRotateCommand($store, $this->box($mode), $this->runner(), new MockClock('2026-11-23 10:00:00')));
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('secrets: 0 re-sealed, 0 already current, 1 left unchanged (0 do not open, 0 key not available, 1 could not be re-sealed)', $tester->getDisplay());
            self::assertSame(['old', 'b2xk'], [$connection->getToken()->keyId, $connection->getToken()->ciphertext], $mode);
        }
    }

    public function testInvalidConnectionsAreReportedApartAndUnavailableKeysAreLogged(): void
    {
        // Audit ZAW-I5: an invalid connection whose token does not open waits for a new token; it never fails the run.
        $invalid = $this->connection('old');
        $invalid->invalidate(new DateTimeImmutable('2026-11-23'));
        $tester = new CommandTester(new SecretsRotateCommand($this->store($invalid), $this->box('unopened'), $this->runner(), new MockClock('2026-11-23 10:00:00')));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 left unchanged', $tester->getDisplay());
        self::assertStringContainsString('1 invalid connection(s) whose token does not open were skipped', $tester->getDisplay());
        // Audit ZAW-I2: an unavailable key is logged with its id and the cause's class only.
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|Stringable, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        foreach (['gone' => 'unavailable', 'old' => 'seal-unavailable'] as $keyId => $mode) {
            $tester = new CommandTester(new SecretsRotateCommand($this->store($this->connection($keyId)), $this->box($mode), $this->runner(), new MockClock('2026-11-23 10:00:00'), $logger));
            self::assertSame(Command::FAILURE, $tester->execute([]));
        }
        self::assertCount(2, $logger->records);
        self::assertSame(['error', ['connection' => self::ID, 'key_id' => 'gone', 'cause' => RuntimeException::class]], [$logger->records[0][0], $logger->records[0][2]]);
        self::assertSame(['connection' => self::ID, 'key_id' => 'new', 'cause' => null], $logger->records[1][2]);
    }

    private function connection(string $keyId): HubSpotConnection
    {
        return new HubSpotConnection(self::ID, 'acme', new HubSpotAccountId('1'), 'HubSpot 1', '5678', new SealedSecret($keyId, 'b2xk'), [], new DateTimeImmutable('2026-11-23'), 'ana', new DateTimeImmutable('2026-11-23'));
    }

    private function store(HubSpotConnection $connection): HubSpotStoreInterface
    {
        $store = $this->createMock(HubSpotStoreInterface::class);
        $store->method('connections')->willReturnOnConsecutiveCalls([['tenant' => 'acme', 'id' => self::ID]], []);
        $store->method('lockConnection')->willReturn($connection);
        $store->expects(self::never())->method('invalidate');

        return $store;
    }

    private function box(string $mode): CrmSecretBoxInterface
    {
        return new class($mode) implements CrmSecretBoxInterface {
            public function __construct(private string $mode)
            {
            }

            public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret
            {
                return in_array($this->mode, ['throws', 'seal-unavailable'], true) ? throw new SecretKeyUnavailableException('new') : new SealedSecret('new', 'bmV3');
            }

            public function unseal(SealedSecret $sealed, SecretContext $context): string
            {
                return match (true) {
                    $this->mode === 'unopened'                                 => throw new SecretUnsealException(),
                    $this->mode === 'unavailable'                              => throw new SecretKeyUnavailableException($sealed->keyId, new RuntimeException('the key file is missing')),
                    $this->mode === 'reopen-fails' && $sealed->keyId === 'new' => throw new SecretUnsealException(),
                    default                                                    => $sealed->keyId === 'old' ? 'fake-credential' : 'another-value',
                };
            }

            public function currentKeyId(): string
            {
                return 'new';
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
}
