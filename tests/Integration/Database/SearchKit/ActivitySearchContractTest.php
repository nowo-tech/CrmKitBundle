<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;

use function array_replace_recursive;

/**
 * 0.11.0: SearchKit's provider contract for the `crm_activities` group: calls titled with the text, each on its own
 * ownerless contact (a hidden row is one whose contact the viewer may not view, an archived one sits on a contact in
 * the trash). No recents (an activity has no page of its own); sensitive (a title may hold a name).
 */
final class ActivitySearchContractTest extends CrmSearchKitTestCase
{
    /** @var array<string, string> activity id => contact id */
    private array $contacts = [];

    protected function bootCrm(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['activities' => ['enabled' => true]], $config);
        parent::bootCrm($merged);
    }

    public function testNoRecentsNoSeeAllAndASensitiveGroup(): void
    {
        $definition = $this->definition();
        self::assertNull($definition->referenceId($this->referenceTo('nowo_crm_kit_show', ['type' => 'contacts', 'id' => '0190a000-0000-7000-8000-000000000001'])));
        self::assertNull($definition->idField());
        self::assertNull($definition->seeAllUrl(new SearchQuery('call', null, 5, null, false, 'ana')));
        self::assertTrue($definition->sensitive());
        self::assertSame(['crm_activities', 'activity', 'tabler:activity', ['r.title']], [$definition->group(), $definition->subjectType(), $definition->icon(), $definition->searchableFields()]);
    }

    protected function definitionId(): string
    {
        return 'nowo_crm_kit.search.activity';
    }

    protected function sensitiveTitles(): bool
    {
        return true;
    }

    protected function supportsRecents(): bool
    {
        return false;
    }

    /**
     * A host's `nowo_crm_kit.record_access_check` deny on the contact is decided in PHP after the SQL visibility
     * filter (as for the contacts group).
     */
    protected function hiddenRowsFilteredInSql(): bool
    {
        return false;
    }

    protected function seed(int|string $tenant, string $text): string
    {
        $contact             = $this->record($tenant, SubjectType::Contact, 'Holder');
        $id                  = $this->asSeeder($tenant, fn (): string => $this->service(EngagementServiceInterface::class)->log(SubjectType::Contact, $contact, new EngagementInput(ActivityKind::Call, new DateTimeImmutable('2026-11-18 09:00', new DateTimeZone('UTC')), $text, null, ActivityDirection::Out)));
        $this->contacts[$id] = $contact;

        return $id;
    }

    protected function seedHidden(int|string $tenant, string $text): string
    {
        $id                    = $this->seed($tenant, $text);
        $this->ctx()->denied[] = 'view:' . $this->contacts[$id];

        return $id;
    }

    protected function seedArchived(int|string $tenant, string $text): string
    {
        $id = $this->seed($tenant, $text);
        $this->sql("UPDATE nowo_crm_contact SET status = 'trashed' WHERE id = ?", [$this->contacts[$id]]);

        return $id;
    }

    protected function reference(int|string $id): SearchReference
    {
        return $this->referenceTo('nowo_crm_kit_show', ['type' => 'contacts', 'id' => $this->contacts[(string) $id] ?? (string) $id]);
    }
}
