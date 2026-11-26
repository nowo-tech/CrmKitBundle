<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\SearchKit;

use DateTimeInterface;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;

/**
 * The SearchKit group of logged activities (`crm_activities`, 0.11.0, with `activities.enabled`): calls, meetings and
 * e-mails of the tenant matched on their title (never their notes or excerpt), newest first. An activity appears only
 * while its primary company or contact is active and the person may view it (the visibility filter in SQL on that
 * record's owner, then `nowo_crm_kit.record_access_check` on it); the result opens the record's page at its activity
 * feed. No recents (an activity has no page of its own).
 */
final class CrmEngagementSearchDefinition extends CrmSearchDefinition
{
    /** The route of a record page. */
    public const SHOW_ROUTE = 'nowo_crm_kit_show';

    /**
     * `crm_activities`.
     */
    public function group(): string
    {
        return 'crm_activities';
    }

    /**
     * `activity` (the kind of the group; the decision is asked on the primary record).
     */
    public function subjectType(): string
    {
        return 'activity';
    }

    /**
     * The translated group label.
     */
    public function label(SearchQuery $query): string
    {
        return $this->trans('search.group.activities', $query, 'Activities');
    }

    /**
     * The icon of the items.
     */
    public function icon(): string
    {
        return 'tabler:activity';
    }

    /**
     * The fields the words are matched against (the title only).
     */
    public function searchableFields(): array
    {
        return ['r.title'];
    }

    /**
     * Activities of the tenant whose primary record is active and inside the visibility filter.
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        $context = $this->context();
        $owners  = $context instanceof CrmContext ? $this->access->visibleOwners($context) : [];
        $qb      = $this->manager()->createQueryBuilder()
            ->select('r.id AS id', 'r.tenantId AS tenantId', 'r.kind AS kind', 'r.title AS title', 'r.occurredAt AS occurredAt', 'r.subjectType AS subjectType', 'r.subjectId AS subjectId', 'COALESCE(co.ownerId, ct.ownerId) AS ownerId', 'co.name AS company', 'ct.displayName AS contact')
            ->from(Engagement::class, 'r')
            ->leftJoin(Company::class, 'co', 'WITH', 'r.subjectType = :crm_company AND co.id = r.subjectId AND co.tenantId = :crm_tenant AND co.status = :crm_active')
            ->leftJoin(Contact::class, 'ct', 'WITH', 'r.subjectType = :crm_contact AND ct.id = r.subjectId AND ct.tenantId = :crm_tenant AND ct.status = :crm_active')
            ->where('r.tenantId = :crm_tenant')->andWhere('co.id IS NOT NULL OR ct.id IS NOT NULL')
            ->setParameter('crm_tenant', (string) $tenant)->setParameter('crm_active', RecordStatus::Active)
            ->setParameter('crm_company', SubjectType::Company)->setParameter('crm_contact', SubjectType::Contact)
            ->orderBy('r.occurredAt', 'DESC')->addOrderBy('r.id', 'DESC');
        self::restrict($qb, 'COALESCE(co.ownerId, ct.ownerId)', $owners);

        return $qb;
    }

    /**
     * One result: the title (or the kind), the kind, the record and the date; the record's page at its feed.
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        /** @var array<string, mixed> $row */
        $row     = (array) $row;
        $kind    = $this->kindOf($row);
        $subject = $this->subject($row);
        $at      = $row['occurredAt'] ?? null;
        $label   = $this->trans('activity_log.kind.' . $kind, $query, $kind);

        return new SearchItem(
            self::field($row, 'title') ?? $label,
            $this->urls->generate(self::SHOW_ROUTE, ['type' => $subject->type->segment(), 'id' => $subject->id->value]) . '#crm-activities',
            self::subtitle([$label, self::field($row, 'company') ?? self::field($row, 'contact'), $at instanceof DateTimeInterface ? $at->format('Y-m-d') : null]),
        );
    }

    /**
     * Sensitive: a title may hold a person's name (SearchKit runs the group only while recents are revalidated or off).
     */
    public function sensitive(): bool
    {
        return true;
    }

    /**
     * No "see all" page.
     */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
    }

    /**
     * No recents of this group.
     */
    public function referenceId(SearchReference $reference): ?string
    {
        return null;
    }

    /**
     * No revalidated recents.
     */
    public function idField(): ?string
    {
        return null;
    }

    /**
     * The primary record of a row.
     */
    protected function subject(array|object $row): SubjectRef
    {
        $row  = (array) $row;
        $kind = $row['subjectType'] ?? null;
        $type = $kind === SubjectType::Company || $kind === SubjectType::Company->value ? SubjectType::Company : SubjectType::Contact;

        return SubjectRef::of($type, self::field($row, 'subjectId') ?? '', self::field($row, 'ownerId'));
    }

    /**
     * The kind of a row (`call`, `meeting`, `email`).
     *
     * @param array<string, mixed> $row
     */
    private function kindOf(array $row): string
    {
        $kind = $row['kind'] ?? null;

        return $kind instanceof ActivityKind ? $kind->value : (self::field($row, 'kind') ?? ActivityKind::Call->value);
    }
}
