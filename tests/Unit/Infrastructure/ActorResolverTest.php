<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\IdentifierActorResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\SecurityCrmAccessChecker;
use Nowo\CrmKitBundle\Tests\Fixtures\TestActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The actor resolver (0.2.1): the default keeps the identifier; a host resolver's stable id is the actor of the access
 * checker and the default directory, named by the resolver.
 */
final class ActorResolverTest extends TestCase
{
    public function testTheResolverDecidesTheActorId(): void
    {
        $tenant = new TenantRef('acme');
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $default = new IdentifierActorResolver();
        self::assertSame('ana', $default->idOf(new InMemoryUser('ana', null)));
        self::assertSame([], $default->resolve(['ana']));

        $resource = new CrmResourceAccessChecker();
        $host     = new TestActorResolver();
        $stable   = TestActorResolver::IDS['ana'];
        self::assertSame($stable, (new SecurityCrmAccessChecker(new AllowAllCrmKitAccessChecker(), $resource, $tokens, false, $host))->currentActor()?->value);
        self::assertSame('ana', (new SecurityCrmAccessChecker(new AllowAllCrmKitAccessChecker(), $resource, $tokens))->currentActor()?->value, 'default: the identifier');

        $directory = new SecurityActorDirectory($tokens, $host);
        $profile   = $directory->actor($tenant, new ActorRef($stable));
        self::assertSame(['Ana Person', 'person@x.test'], [$profile?->name, $profile?->email]);
        self::assertNull($directory->actor($tenant, new ActorRef('ana')), 'the identifier is no longer the actor');
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('leo', null), 'main'));
        self::assertSame('leo', $directory->owners($tenant)[0]->name, 'unknown to the resolver: named by the identifier');
    }
}
