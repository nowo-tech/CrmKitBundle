<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function str_contains;

/**
 * The test host's projects: `p1`, `p2` in `acme`, `p9` in `beta` (byte-exact ids).
 */
final readonly class TestProjects implements CrmHostObjectResolverInterface
{
    public const PROJECTS = ['acme' => ['p1' => 'Website', 'p2' => 'Shop'], 'beta' => ['p9' => 'Beta app']];

    public function type(): string
    {
        return 'project';
    }

    public function resolve(TenantRef $tenant, array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $label = self::PROJECTS[$tenant->value][$id] ?? null;
            if ($label !== null) {
                $found[$id] = new HostObjectView($id, $label, '/projects/' . $id);
            }
        }
        // A misbehaving host answers a look-alike key: the CRM must not show it.
        if (isset($found['p2'])) {
            $found['P2'] = new HostObjectView('P2', 'Look-alike', '/projects/P2');
        }

        return $found;
    }

    public function search(TenantRef $tenant, string $text, int $limit): array
    {
        $found = [];
        foreach (self::PROJECTS[$tenant->value] ?? [] as $id => $label) {
            if (str_contains($label, $text)) {
                $found[] = new HostObjectView($id, $label, '/projects/' . $id);
            }
        }

        return $found;
    }
}
