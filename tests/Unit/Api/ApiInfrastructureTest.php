<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Api;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiGrant;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiReach;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiCustom;
use Nowo\CrmKitBundle\Api\Input\CrmApiFields;
use Nowo\CrmKitBundle\Api\Read\CrmApiPresenter;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Api\ApiIdempotency;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\PrincipalCaller;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\PrincipalCallerFactory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\SecurityUserCrmApiAuthenticator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ScopedRecordVisibility;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_fill_keys;
use function array_map;
use function range;

/**
 * The API's adapters without a kernel (0.3.0): a principal caller on an area checker that is not tenant-aware, the
 * factory on the default actor directory, the default authenticator.
 */
final class ApiInfrastructureTest extends TestCase
{
    public function testAPrincipalCallerOnAPlainAreaChecker(): void
    {
        $tenant    = new TenantRef('acme');
        $principal = new CrmApiPrincipal(new InMemoryUser('ana', null), new ActorRef('ana'), new CrmApiGrant(CrmApiLevel::Edit, true, false, true), CrmApiReach::everything(), 't', 'T');
        $areas     = new AllowAllCrmKitAccessChecker();
        $caller    = new PrincipalCaller($principal, $tenant, $areas, new CrmResourceAccessChecker(), new ScopedRecordVisibility($areas));
        $subject   = SubjectRef::of(SubjectType::Company, '0190a0a0-0000-7000-8000-000000000001', 'leo');
        foreach ([CrmPermission::View, CrmPermission::Create, CrmPermission::Edit, CrmPermission::Delete, CrmPermission::AmountsView] as $permission) {
            self::assertTrue($caller->isGranted($permission, $tenant, $subject), $permission->value);
        }
        self::assertFalse($caller->isGranted(CrmPermission::Manage, $tenant), 'the grant is below manage');
        self::assertFalse($caller->isGranted(CrmPermission::Export, $tenant), 'never through the API');
        self::assertFalse($caller->isGranted(CrmPermission::View, new TenantRef('beta')), 'another tenant than the call\'s');
        self::assertNull($caller->selfOwners($tenant));
        $manager = new PrincipalCaller(new CrmApiPrincipal(new InMemoryUser('ana', null), new ActorRef('ana'), new CrmApiGrant(CrmApiLevel::Manage), CrmApiReach::limitedTo([]), 't', 'T'), $tenant, $areas, new CrmResourceAccessChecker(), new ScopedRecordVisibility($areas));
        self::assertTrue($manager->isGranted(CrmPermission::Manage, $tenant));
        self::assertNull($manager->tenant());
    }

    public function testTheFactoryOnTheDefaultDirectory(): void
    {
        $areas     = new AllowAllCrmKitAccessChecker();
        $principal = new CrmApiPrincipal(new InMemoryUser('ana', null), new ActorRef('ana'), new CrmApiGrant(CrmApiLevel::Edit), CrmApiReach::everything(), 't', 'T');
        $resolver  = new class implements CrmActorResolverInterface {
            public function idOf(UserInterface $user): string
            {
                return $user->getUserIdentifier() === 'bad' ? ' bad' : $user->getUserIdentifier();
            }

            public function resolve(array $ids): array
            {
                return isset($ids[0]) && $ids[0] === 'ana' ? ['ana' => new ActorProfile(new ActorRef('ana'), 'Ana Lovelace')] : [];
            }
        };
        $factory = new PrincipalCallerFactory($areas, new CrmResourceAccessChecker(), new ScopedRecordVisibility($areas), new SecurityActorDirectory(), $resolver);
        $owners  = $factory->for($principal, new TenantRef('acme'))->selfOwners(new TenantRef('acme'));
        self::assertSame('Ana Lovelace', $owners[0]->name ?? null);
        $plain = new PrincipalCallerFactory($areas, new CrmResourceAccessChecker(), new ScopedRecordVisibility($areas), new SecurityActorDirectory());
        self::assertSame('ana', $plain->for($principal, new TenantRef('acme'))->selfOwners(new TenantRef('acme'))[0]->name ?? null);

        $tokens = new TokenStorage();
        $auth   = new SecurityUserCrmApiAuthenticator($tokens, ['level' => 'edit', 'amounts' => true, 'consents' => false, 'destructive' => false], $resolver);
        self::assertNull($auth->authenticate(new Request()));
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('bad', null), 'main'));
        self::assertNull($auth->authenticate(new Request()), 'an invalid actor id');
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ana', null), 'main'));
        $principal = (new SecurityUserCrmApiAuthenticator($tokens, ['level' => 'edit', 'amounts' => true, 'consents' => false, 'destructive' => false], $resolver))->authenticate(new Request());
        self::assertNotNull($principal);
        self::assertSame([CrmApiLevel::Edit, true, 'API'], [$principal->grant->level, $principal->grant->amounts, $principal->tokenName]);
    }

    public function testInputLimits(): void
    {
        $plain = new class implements CrmKitAccessCheckerInterface {
            public function canAccess(?object $user): bool
            {
                return true;
            }

            public function canCreate(?object $user): bool
            {
                return true;
            }

            public function canEdit(?object $user): bool
            {
                return false;
            }

            public function canDelete(?object $user): bool
            {
                return true;
            }

            public function canImport(?object $user): bool
            {
                return false;
            }

            public function canExport(?object $user): bool
            {
                return false;
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
                return false;
            }
        };
        $tenant    = new TenantRef('acme');
        $principal = new CrmApiPrincipal(new InMemoryUser('ana', null), new ActorRef('ana'), new CrmApiGrant(CrmApiLevel::Manage, true, false, true), CrmApiReach::everything(), 't', 'T');
        $caller    = new PrincipalCaller($principal, $tenant, $plain, new CrmResourceAccessChecker(), new ScopedRecordVisibility($plain));
        self::assertSame([true, true, false, true, true, false], [
            $caller->isGranted(CrmPermission::View, $tenant),
            $caller->isGranted(CrmPermission::Create, $tenant),
            $caller->isGranted(CrmPermission::Edit, $tenant),
            $caller->isGranted(CrmPermission::Delete, $tenant),
            $caller->isGranted(CrmPermission::AmountsView, $tenant),
            $caller->isGranted(CrmPermission::Manage, $tenant),
        ]);
        $views  = [new ConsentView('c1', 'old_purpose', true, ConsentSource::Form, null, null, new DateTimeImmutable())];
        $states = CrmApiPresenter::consents('x', $views, ['newsletter'], false)->fact('purposes');
        self::assertIsArray($states);
        self::assertSame(['newsletter', 'old_purpose'], array_column($states, 'purpose'));
        self::assertSame('acme', (new ApiIdempotency('s', 'k', 'acme', 'o', 'f', new DateTimeImmutable(), new DateTimeImmutable()))->tenantId);
        self::assertSame('leo', CrmApiFields::actor(['a' => 'leo'], 'a')?->value);
        try {
            CrmApiCustom::parse(['custom' => array_fill_keys(array_map(static fn (int $i): string => 'k' . $i, range(0, 200)), 1)]);
            self::fail('accepted');
        } catch (CrmApiRefusal $e) {
            self::assertSame(CrmApiCode::INPUT_MALFORMED, $e->errorCode);
        }
    }
}
