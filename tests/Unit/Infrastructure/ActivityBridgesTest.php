<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use ArrayObject;
use Doctrine\DBAL\Connection;
use Nowo\CrmKitBundle\Bridge\NotifyKit\CrmNotifyKitTypes;
use Nowo\CrmKitBundle\Bridge\NotifyKit\NotifyKitCrmNotifier;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\NotifyResult;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_string;
use function iterator_to_array;

/**
 * 0.11.0: the NotifyKit bridge, its types and the runner's rollback to the entry level (the lost-savepoint pattern).
 */
final class ActivityBridgesTest extends TestCase
{
    private const ID = '0190a000-0000-7000-8000-000000000001';

    public function testNotifyKitBridge(): void
    {
        $notifier = new class implements NotifierInterface {
            /** @var list<NotificationRequest> */
            public array $requests = [];

            public function notify(NotificationRequest $request): NotifyResult
            {
                $this->requests[] = $request;

                return new NotifyResult(1);
            }
        };
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $route, array $params): string {
            if ($route === 'nowo_crm_kit_deal_show') {
                throw new RouteNotFoundException($route);
            }

            return '/crm/' . (is_string($params['type'] ?? null) ? $params['type'] : '') . '/' . (is_string($params['id'] ?? null) ? $params['id'] : '');
        });
        $bridge = new NotifyKitCrmNotifier($notifier, $urls, 'member');
        $tenant = new TenantRef('acme');
        $bridge->notify(new CrmNotification(CrmNotification::MENTION, $tenant, [new ActorRef('leo'), new ActorRef('leo'), new ActorRef('eva')], new ActorRef('ana'), SubjectRef::of(SubjectType::Contact, self::ID), 'crm-note:n1'));
        $bridge->notify(new CrmNotification(CrmNotification::MENTION, $tenant, [new ActorRef('leo')], new ActorRef('ana'), SubjectRef::of(SubjectType::Deal, self::ID), 'crm-note:n2'));
        $bridge->notify(new CrmNotification(CrmNotification::MENTION, $tenant, [], new ActorRef('ana'), SubjectRef::of(SubjectType::Deal, self::ID), 'crm-note:n3'));
        self::assertCount(2, $notifier->requests);
        $first = $notifier->requests[0];
        self::assertSame(['crm.mention', 'crm-note:n1', '/crm/contacts/' . self::ID, [], null], [$first->type, $first->target, $first->url, $first->params, $first->context]);
        self::assertSame(['member:leo', 'member:eva'], [$first->recipients[0]->type . ':' . $first->recipients[0]->id, $first->recipients[1]->type . ':' . $first->recipients[1]->id]);
        self::assertSame('ana', $first->actor?->id);
        self::assertCount(2, $first->involved);
        self::assertSame('/', $notifier->requests[1]->url, 'no CRM page: the site root');
        $types = new CrmNotifyKitTypes();
        $list  = iterator_to_array($types->types());
        self::assertInstanceOf(TypeDefinition::class, $list[0]);
        self::assertSame(['crm.mention', 'crm', 'NowoCrmKitBundle', null], [$list[0]->key, $list[0]->category, $list[0]->titleDomain, $list[0]->permission]);
        self::assertSame('crm', iterator_to_array($types->categories())[0]->key);
    }

    public function testRollBackToTheEntryLevel(): void
    {
        $connection = $this->createMock(Connection::class);
        $level      = new ArrayObject(['level' => 2]);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static fn (): int => (int) $level['level']);
        $connection->expects(self::exactly(2))->method('rollBack')->willReturnCallback(static function () use ($level): void {
            $level['level'] = (int) $level['level'] - 1;
        });
        $connection->expects(self::never())->method('close');
        DoctrineTransactionRunner::rollBackTo($connection, 0);

        // A lost savepoint: the rollback fails, the connection is closed and the failure dropped.
        $lost = $this->createMock(Connection::class);
        $lost->method('isTransactionActive')->willReturn(true);
        $lost->method('getTransactionNestingLevel')->willReturn(2);
        $lost->method('rollBack')->willThrowException(new RuntimeException('SAVEPOINT does not exist'));
        $lost->expects(self::once())->method('close');
        DoctrineTransactionRunner::rollBackTo($lost, 0);

        $idle = $this->createMock(Connection::class);
        $idle->method('isTransactionActive')->willReturn(false);
        $idle->expects(self::never())->method('rollBack');
        DoctrineTransactionRunner::rollBackTo($idle, 0);
    }
}
