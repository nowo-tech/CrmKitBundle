<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotList;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotSchemaView;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;

/**
 * HubSpot imports (0.10.0, `import.hubspot.enabled`): start ⇒ mapping ⇒ confirm, then the same queue as CSV
 * ({@see ImportServiceInterface::run()}, `cancel()`, `preview()`, `unfinished()`, the resume command). `import`.
 */
interface HubSpotImportServiceInterface
{
    /**
     * The tenant's HubSpot lists of companies or contacts the start step offers (needs `crm.lists.read`; none
     * without it).
     *
     * @return list<HubSpotList>
     */
    public function lists(): array;

    /**
     * Creates a `hubspot` import in `preview` for the scope (the counts read from HubSpot); its id.
     *
     * @throws InvalidInputException `_`: error.hubspot_not_connected; `token` keys as for connect; `lists`:
     *                               error.hubspot_list_unknown
     * @throws ConflictException crm_import_running (another HubSpot import of the tenant is in preview or running)
     */
    public function start(HubSpotImportScope $scope, DuplicatePolicy $policy = DuplicatePolicy::Skip): string;

    /**
     * What the mapping step offers: properties per object (with the current mapping), the lists and the tags.
     */
    public function schema(string $importId): HubSpotSchemaView;

    /**
     * Claims the import with the mapping (one confirm wins, as for CSV); tags marked "create" are created in the claim
     * unit. Policy `update` also needs `edit`.
     *
     * @throws InvalidInputException `mapping`: error.mapping
     * @throws ConflictException crm_import_running
     */
    public function confirm(string $importId, HubSpotMapping $mapping, DuplicatePolicy $policy, int $expectedVersion): void;
}
