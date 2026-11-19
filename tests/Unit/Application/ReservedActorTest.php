<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\PartyCatalogue;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorProfilesInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestPorts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Audit VD2-6 / VD2-7: the reserved ids are filtered where every host adapter passes (visible owners, names), and the
 * default directory never makes a signed-in person the demo actor.
 */
final class ReservedActorTest extends TestCase
{
    public function testAHostVisibilityServiceCannotRevealThePlaceholder(): void
    {
        $ports  = new TestPorts(new TestContext());
        $access = new CrmAccess($ports, $ports, $ports, new MockClock(), new class implements CrmRecordVisibilityInterface {
            public function visibleOwners(TenantRef $tenant, ActorRef $actor): array
            {
                return [ActorRef::ANONYMISED, 'leo'];
            }
        });
        self::assertSame(['leo'], $access->visibleOwners($access->base()));
        self::assertNull((new CrmAccess($ports, $ports, $ports, new MockClock()))->visibleOwners($access->base()));
    }

    public function testThePlaceholderIsNeverSentForANameAndAnonymousIsNoPerson(): void
    {
        $profiles = new class implements CrmActorProfilesInterface {
            /** @var list<list<string>> */
            public array $asked = [];

            public function resolve(array $ids): array
            {
                $this->asked[] = $ids;

                return [];
            }
        };
        $directory = new class implements CrmActorDirectoryInterface {
            /** @var list<string> */
            public array $asked = [];

            public function actor(TenantRef $tenant, ActorRef $actor): ActorProfile
            {
                $this->asked[] = $actor->value;

                return new ActorProfile($actor, 'Name of ' . $actor->value);
            }

            public function owners(TenantRef $tenant): array
            {
                return [];
            }
        };
        $views  = new RecordViews($this->createStub(CrmStoreInterface::class), $directory, new PartyCatalogue(), $profiles);
        $tenant = new TenantRef('acme');
        self::assertSame(['ana' => 'Name of ana'], $views->actorNames($tenant, [ActorRef::ANONYMISED, 'ana', null]));
        self::assertNull($views->actorName($tenant, ActorRef::ANONYMISED));
        self::assertSame([['ana']], $profiles->asked);
        self::assertSame(['ana'], $directory->asked);

        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('anonymous', null), 'main'));
        self::assertSame([], (new SecurityActorDirectory($tokens))->owners($tenant));
    }
}
