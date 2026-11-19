<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordAccessCheckEvent;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * REQ-UI-002 on the pages with the bundle's own checkers (no host access checker): anonymous ⇒ the firewall's entry
 * point (401 with HTTP Basic), a user without the area role ⇒ 403, an admin ⇒ 200; the area roles split the actions;
 * `nowo_crm_kit.record_access_check` listeners grant or deny one record; tenants stay apart.
 */
final class SecurityTest extends CrmTestCase
{
    public function testPagesFollowTheSecurityConfiguration(): void
    {
        $this->boot(['access_checker' => null, 'security' => ['manage_roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'export_roles' => []]]);
        $this->ctx()->actor  = null;
        $this->ctx()->tenant = 'acme';
        self::assertSame(401, $this->httpStatus('GET', '/crm/companies'), 'anonymous: the firewall entry point');
        self::assertSame(403, $this->as('ana', 'GET', '/crm/companies'), 'no access role');
        self::assertSame(200, $this->as('root', 'GET', '/crm/companies'));
        self::assertSame(200, $this->as('root', 'GET', '/crm/companies/new'));

        // The guard answers each area, tenant-wide.
        $this->as('root', 'GET', '/crm/companies');
        $guard = $this->service(CrmKitGuard::class);
        self::assertTrue($guard->grants()[CrmPermission::Export->value]);

        // A record: a listener denies the view of one company (403 on its page), grants nothing else.
        $this->client->submit($this->client->request('GET', '/crm/companies/new')->filter('form[name="crm_record"]')->form(), ['crm_record[name]' => 'Acme']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $page       = (string) $this->client->getResponse()->headers->get('Location');
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $seen = [];
        $dispatcher->addListener(CrmKitEvents::RECORD_ACCESS_CHECK, static function (RecordAccessCheckEvent $event) use (&$seen): void {
            $seen[] = $event->getAction() . ':' . $event->getActorId() . ':' . $event->getTenantKey();
            if ($event->getAction() === 'view') {
                $event->deny();
            }
        });
        self::assertSame(403, $this->as('root', 'GET', $page));
        self::assertContains('view:root:acme', $seen);

        // Another tenant: the same person and id ⇒ 404, never 403.
        $this->ctx()->tenant = 'beta';
        self::assertSame(404, $this->as('root', 'GET', $page));
    }

    private function as(string $user, string $method, string $path): int
    {
        $this->client->loginUser(new InMemoryUser($user, 'x', $user === 'root' ? ['ROLE_ADMIN'] : ['ROLE_USER']), 'main');
        $this->client->request($method, $path);

        return $this->client->getResponse()->getStatusCode();
    }
}
