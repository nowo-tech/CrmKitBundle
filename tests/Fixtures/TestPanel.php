<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\PanelContext;
use Nowo\CrmKitBundle\Domain\Value\PanelItem;
use Nowo\TransactionContract\TransientStorageFailure;
use RuntimeException;

/**
 * A host panel: the projects linked to a company (the test host's "projects" module).
 */
final readonly class TestPanel implements CrmPanelProviderInterface
{
    public function __construct(private TestContext $context)
    {
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
        if ($this->context->panelTransient) {
            throw new TransientStorageFailure(null, 'Deadlock in the host.');
        }
        if ($this->context->panelFails) {
            throw new RuntimeException('Host module down.');
        }
        $items = [];
        foreach ($context->links as $link) {
            if ($link->type === 'project') {
                $items[] = new PanelItem('Project ' . $link->id, '/projects/' . $link->id, 'for ' . $context->tenant->value, 'active', 'success');
            }
        }

        return new Panel('Projects', $items, null, null, '/projects', 'No projects', Panel::SIDE);
    }
}
