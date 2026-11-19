<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\SearchKit;

use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Search\Query\SearchWords;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;

use function mb_strtolower;
use function str_contains;

/**
 * The SearchKit group of deals (`crm_deals`, 0.3.0, with `pipeline.enabled`): deals of the tenant that are not in the
 * trash, matched on their title, their company's name and their stage's name, newest change first. A deal the person
 * may not view never appears (visibility filter in SQL, then `nowo_crm_kit.record_access_check` with the subject type
 * `deal`). The company is joined only when it is active and inside the visibility filter, and its name is shown (and
 * kept as a match) only when the person may view it, so a hidden company is never revealed through its deals. The
 * subtitle shows the amount only to a person with `amounts_view`; amounts are never searched.
 */
final class CrmDealSearchDefinition extends CrmSearchDefinition
{
    /** The route of a deal page. */
    public const SHOW_ROUTE = 'nowo_crm_kit_deal_show';

    /** The route of the deal list. */
    public const LIST_ROUTE = 'nowo_crm_kit_deals';

    /**
     * `crm_deals`.
     */
    public function group(): string
    {
        return 'crm_deals';
    }

    /**
     * `deal` (the subject type of the host event).
     */
    public function subjectType(): string
    {
        return SubjectType::Deal->value;
    }

    /**
     * The translated group label.
     */
    public function label(SearchQuery $query): string
    {
        return $this->trans('search.group.deals', $query, 'Deals');
    }

    /**
     * The icon of the items.
     */
    public function icon(): string
    {
        return 'tabler:briefcase';
    }

    /**
     * The fields the words are matched against (never the amount).
     */
    public function searchableFields(): array
    {
        return ['r.title', 'co.name', 's.name'];
    }

    /**
     * Deals of the tenant outside the trash under the visibility filter, with their stage and their visible company.
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        $context = $this->context();
        $owners  = $context instanceof CrmContext ? $this->access->visibleOwners($context) : [];
        $company = 'co.id = r.companyId AND co.tenantId = :crm_tenant AND co.status = :crm_active';
        if ($owners === []) {
            $company .= ' AND co.ownerId IS NULL';
        } elseif ($owners !== null) {
            $company .= ' AND (co.ownerId IS NULL OR co.ownerId IN (:crm_owners))';
        }
        $qb = $this->manager()->createQueryBuilder()
            ->select('r.id AS id', 'r.tenantId AS tenantId', 'r.ownerId AS ownerId', 'r.title AS title', 'r.amount AS amount', 'r.currency AS currency', 's.name AS stage', 'co.id AS companyId', 'co.name AS company', 'co.ownerId AS companyOwnerId')
            ->from(Deal::class, 'r')
            ->innerJoin(Stage::class, 's', 'WITH', 's.id = r.stageId AND s.tenantId = :crm_tenant')
            ->leftJoin(Company::class, 'co', 'WITH', $company)
            ->where('r.tenantId = :crm_tenant')->andWhere('r.status <> :crm_trashed')
            ->setParameter('crm_tenant', (string) $tenant)->setParameter('crm_trashed', DealStatus::Trashed)->setParameter('crm_active', RecordStatus::Active)
            ->orderBy('r.updatedAt', 'DESC')->addOrderBy('r.id', 'DESC');
        self::restrict($qb, 'r.ownerId', $owners);

        return $qb;
    }

    /**
     * The candidates the person may view (the deal decision); a row whose company the person may not view is kept only
     * when every word matches its title or stage, so a hidden company's name is never a match.
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $context = $this->context();
        $words   = SearchWords::words($query->text);
        $kept    = [];
        foreach (parent::filterVisible($rows, $tenant, $query) as $row) {
            /** @var array<string, mixed> $row */
            $row = (array) $row;
            if ($context instanceof CrmContext && self::field($row, 'companyId') !== null && !$this->companyVisible($context, $row)) {
                // The company is hidden from this person: every word must match the title or the stage alone (its name
                // is left out of the result by toItem()).
                $text = mb_strtolower((self::field($row, 'title') ?? '') . "\n" . (self::field($row, 'stage') ?? ''));
                foreach ($words as $word) {
                    if (!str_contains($text, $word)) {
                        continue 2;
                    }
                }
            }
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * One result: the title, the stage, the visible company and (with `amounts_view`) the amount, the deal's page.
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        /** @var array<string, mixed> $row */
        $row     = (array) $row;
        $amount  = self::field($row, 'amount');
        $money   = $amount !== null && $this->access->can(CrmPermission::AmountsView) ? Money::normalise($amount) . ' ' . (self::field($row, 'currency') ?? '') : null;
        $context = $this->context();
        // SearchKit shows the rows as fetched: a company the person may not view is left out here (one decision per
        // shown deal).
        $company = self::field($row, 'companyId') !== null && $context instanceof CrmContext && $this->companyVisible($context, $row) ? self::field($row, 'company') : null;

        return new SearchItem(
            self::field($row, 'title') ?? '',
            $this->urls->generate(self::SHOW_ROUTE, ['id' => self::field($row, 'id')]),
            self::subtitle([self::field($row, 'stage'), $company, $money]),
        );
    }

    /**
     * The filtered deal list.
     */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return SeeAllLink::build($this->urls, self::LIST_ROUTE, [], $query);
    }

    /**
     * The deal id of a recent of this group (the deal page route).
     */
    public function referenceId(SearchReference $reference): ?string
    {
        return self::referenced($reference, self::SHOW_ROUTE, []);
    }

    /**
     * The subject of a row.
     */
    protected function subject(array|object $row): SubjectRef
    {
        return SubjectRef::of(SubjectType::Deal, self::field($row, 'id') ?? '', self::field($row, 'ownerId'));
    }

    /**
     * Whether the deal's company may be viewed.
     *
     * @param array<string, mixed> $row
     */
    private function companyVisible(CrmContext $context, array $row): bool
    {
        return $this->may($context, SubjectRef::of(SubjectType::Company, self::field($row, 'companyId') ?? '', self::field($row, 'companyOwnerId')));
    }
}
