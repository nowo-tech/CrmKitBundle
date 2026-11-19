<?php

declare(strict_types=1);

namespace App\Crm;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\PanelContext;
use Nowo\CrmKitBundle\Domain\Value\PanelItem;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The demo's own "projects" module: a host object type the CRM links to by id (P-1 … P-3), and a panel on the 360°
 * page that lists the projects linked to a company. Autoconfigured (tags of the two interfaces). Stateless.
 */
final readonly class DemoProjects implements CrmHostObjectResolverInterface, CrmPanelProviderInterface
{
    private const PROJECTS = ['P-1' => 'Website relaunch', 'P-2' => 'Mobile app', 'P-3' => 'Support contract'];

    public function type(): string
    {
        return 'project';
    }

    public function resolve(TenantRef $tenant, array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            if (isset(self::PROJECTS[$id])) {
                $found[$id] = new HostObjectView($id, $id . ' · ' . self::PROJECTS[$id]);
            }
        }

        return $found;
    }

    public function search(TenantRef $tenant, string $text, int $limit): array
    {
        return [];
    }

    public function key(): string
    {
        return 'projects';
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::Company;
    }

    public function panel(PanelContext $context): Panel
    {
        $items = [];
        foreach ($context->links as $link) {
            if ($link->type === 'project' && isset(self::PROJECTS[$link->id])) {
                $items[] = new PanelItem(self::PROJECTS[$link->id], null, $link->id, 'active', 'success');
            }
        }

        return new Panel('Projects (demo module)', $items, null, null, null, 'Link a project (P-1, P-2 or P-3) to see it here.', Panel::SIDE);
    }
}
