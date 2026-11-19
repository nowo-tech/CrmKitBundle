<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host resolver with stable ids: each sign-in identifier maps to a UUID, so an identifier (e-mail) change keeps the
 * actor. Counts its resolve() calls (one per page).
 */
final class TestActorResolver implements CrmActorResolverInterface
{
    /** identifier => stable id */
    public const IDS = ['root' => '0190aaaa-0000-7000-8000-000000000001', 'ana' => '0190aaaa-0000-7000-8000-000000000002', 'root-renamed@x.test' => '0190aaaa-0000-7000-8000-000000000001'];

    /** stable id => display name */
    public const NAMES = ['0190aaaa-0000-7000-8000-000000000001' => 'Root Person', '0190aaaa-0000-7000-8000-000000000002' => 'Ana Person'];

    /** @var list<list<string>> the ids asked per call */
    public array $calls = [];

    public function idOf(UserInterface $user): string
    {
        return self::IDS[$user->getUserIdentifier()] ?? $user->getUserIdentifier();
    }

    public function resolve(array $ids): array
    {
        $this->calls[] = $ids;
        $profiles      = [];
        foreach ($ids as $id) {
            if (isset(self::NAMES[$id])) {
                $profiles[$id] = new ActorProfile(new ActorRef($id), self::NAMES[$id], 'person@x.test');
            }
        }

        return $profiles;
    }
}
