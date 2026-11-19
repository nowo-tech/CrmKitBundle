<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\PanelContext;

/**
 * A host panel on the 360° page (contract section 8.2; tag `nowo_crm_kit.panel`, autoconfigured). The host checks its
 * own permissions and returns only what the actor may see.
 */
interface CrmPanelProviderInterface
{
    /** Service tag. */
    public const PANEL_TAG = 'nowo_crm_kit.panel';

    /**
     * A stable key (`^[a-z0-9_-]{1,40}$`).
     */
    public function key(): string;

    /**
     * Whether the provider has a panel for this kind of record.
     */
    public function supports(SubjectType $type): bool;

    /**
     * The panel, or null to show nothing.
     */
    public function panel(PanelContext $context): ?Panel;
}
