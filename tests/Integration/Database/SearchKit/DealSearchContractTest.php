<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\SearchKitBundle\Model\SearchReference;

/**
 * SearchKit's provider contract for the `crm_deals` group: deals titled with the text, of an ownerless company named
 * "Holder" in a deal funnel of the tenant.
 */
final class DealSearchContractTest extends CrmSearchKitTestCase
{
    /** @var array<string, array{string, string}> tenant => [funnel id, company id] */
    private array $holders = [];

    protected function definitionId(): string
    {
        return 'nowo_crm_kit.search.deal';
    }

    protected function seed(int|string $tenant, string $text): string
    {
        [$funnel, $company] = $this->holder($tenant);

        return $this->asSeeder($tenant, fn (): string => $this->service(DealService::class)->create(new DealInput($text, $funnel, companyId: $company)));
    }

    protected function seedHidden(int|string $tenant, string $text): string
    {
        $id                    = $this->seed($tenant, $text);
        $this->ctx()->denied[] = 'view:' . $id;

        return $id;
    }

    protected function seedArchived(int|string $tenant, string $text): string
    {
        $id = $this->seed($tenant, $text);
        $this->asSeeder($tenant, fn () => $this->service(DealService::class)->trash($id));

        return $id;
    }

    protected function reference(int|string $id): SearchReference
    {
        return $this->referenceTo('nowo_crm_kit_deal_show', ['id' => (string) $id]);
    }

    /**
     * The tenant's deal funnel and holder company (created once per test).
     *
     * @return array{string, string}
     */
    private function holder(int|string $tenant): array
    {
        return $this->holders[(string) $tenant] ??= [
            $this->asSeeder($tenant, fn (): string => $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales')),
            $this->record($tenant, SubjectType::Company, 'Holder'),
        ];
    }
}
