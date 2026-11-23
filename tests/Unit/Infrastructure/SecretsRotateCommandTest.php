<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\SecretsRotateCommand;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `nowo:crm-kit:secrets:rotate` never loses a token (0.10.1, audit ZAM-D2): a new seal that cannot be made, or that
 * does not open again to the same token, is never written, and the command exits with 1.
 */
final class SecretsRotateCommandTest extends TestCase
{
    private const ID = '01890000-0000-7000-8000-000000000001';

    public function testASealThatFailsOrDoesNotOpenAgainIsNeverWritten(): void
    {
        foreach (['throws', 'differs'] as $mode) {
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

    private function box(string $mode): CrmSecretBoxInterface
    {
        return new class($mode) implements CrmSecretBoxInterface {
            public function __construct(private string $mode)
            {
            }

            public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret
            {
                return $this->mode === 'throws' ? throw new SecretKeyUnavailableException('new') : new SealedSecret('new', 'bmV3');
            }

            public function unseal(SealedSecret $sealed, SecretContext $context): string
            {
                return $sealed->keyId === 'old' ? 'fake-credential' : 'another-value';
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
