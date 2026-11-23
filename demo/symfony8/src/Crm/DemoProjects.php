<?php

declare(strict_types=1);

namespace App\Crm;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectSearchInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectTypeLabelInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\HostTypeLabel;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\PanelContext;
use Nowo\CrmKitBundle\Domain\Value\PanelItem;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_slice;
use function mb_stripos;

/**
 * The demo's own "projects" module: a host object type the CRM links to by id (P-1 … P-3), and a panel on the 360°
 * page that lists the projects linked to a company. Since 0.7.0 it also names its type and offers the link picker
 * (the optional label and search capabilities). Autoconfigured (tags of the two interfaces). Stateless.
 */
final readonly class DemoProjects implements CrmHostObjectResolverInterface, CrmHostObjectTypeLabelInterface, CrmHostObjectSearchInterface, CrmPanelProviderInterface
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

    public function typeLabel(): HostTypeLabel
    {
        return HostTypeLabel::text('Project');
    }

    public function search(TenantRef $tenant, string $text, int $limit): array
    {
        $found = [];
        foreach (self::PROJECTS as $id => $name) {
            if (mb_stripos($id . ' ' . $name, $text) !== false) {
                $found[] = new HostObjectView($id, $id . ' · ' . $name);
            }
        }

        return array_slice($found, 0, $limit);
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
