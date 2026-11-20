<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\SearchKitBundle\Model\SearchReference;

/**
 * SearchKit's provider contract for the `crm_companies` group.
 */
final class CompanySearchContractTest extends CrmSearchKitTestCase
{
    protected function definitionId(): string
    {
        return 'nowo_crm_kit.search.company';
    }

    protected function seed(int|string $tenant, string $text): string
    {
        return $this->record($tenant, SubjectType::Company, $text);
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
        $this->sql("UPDATE nowo_crm_company SET status = 'trashed' WHERE id = ?", [$id]);

        return $id;
    }

    protected function reference(int|string $id): SearchReference
    {
        return $this->referenceTo('nowo_crm_kit_show', ['type' => 'companies', 'id' => (string) $id]);
    }
}
