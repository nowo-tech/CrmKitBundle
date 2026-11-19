<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\SearchKitBundle\Model\SearchReference;

/**
 * SearchKit's provider contract for the `crm_contacts` group.
 */
final class ContactSearchContractTest extends CrmSearchKitTestCase
{
    protected function definitionId(): string
    {
        return 'nowo_crm_kit.search.contact';
    }

    protected function seed(int|string $tenant, string $text): string
    {
        return $this->record($tenant, SubjectType::Contact, $text);
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
        $this->sql("UPDATE nowo_crm_contact SET status = 'trashed' WHERE id = ?", [$id]);

        return $id;
    }

    protected function reference(int|string $id): SearchReference
    {
        return $this->referenceTo('nowo_crm_kit_show', ['type' => 'contacts', 'id' => (string) $id]);
    }
}
