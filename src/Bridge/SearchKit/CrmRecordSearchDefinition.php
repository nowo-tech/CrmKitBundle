<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\SearchKit;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The SearchKit group of companies (`crm_companies`) or of contacts (`crm_contacts`), one service per type (0.3.0,
 * contract section 12): active records of the tenant; companies by name, legal name, tax id key, website domain and
 * e-mail, contacts by sort and display name, e-mail key, phone and mobile as typed (no digit normalisation). A scope
 * that is a company id searches that company's active contacts (an active company of the tenant); any other scope, and
 * any scope on companies, finds nothing. Titles and subtitles are record data, rendered by SearchKit as text.
 */
final class CrmRecordSearchDefinition extends CrmSearchDefinition
{
    /** The route of a record page. */
    public const SHOW_ROUTE = 'nowo_crm_kit_show';

    /** The route of a list. */
    public const LIST_ROUTE = 'nowo_crm_kit_list';

    /**
     * Wires the type and the collaborators.
     */
    public function __construct(
        private readonly SubjectType $type,
        CrmAccess $access,
        DoctrineTransactionRunner $runner,
        UrlGeneratorInterface $urls,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct($access, $runner, $urls, $translator);
    }

    /**
     * `crm_companies` or `crm_contacts`.
     */
    public function group(): string
    {
        return 'crm_' . $this->type->segment();
    }

    /**
     * `company` or `contact` (the subject type of the host event).
     */
    public function subjectType(): string
    {
        return $this->type->value;
    }

    /**
     * The translated group label.
     */
    public function label(SearchQuery $query): string
    {
        return $this->trans('search.group.' . $this->type->segment(), $query, $this->type === SubjectType::Company ? 'Companies' : 'Contacts');
    }

    /**
     * The icon of the items.
     */
    public function icon(): string
    {
        return $this->type === SubjectType::Company ? 'tabler:building' : 'tabler:user';
    }

    /**
     * The fields the words are matched against.
     */
    public function searchableFields(): array
    {
        return $this->type === SubjectType::Company
            ? ['r.nameKey', 'r.legalName', 'r.taxIdKey', 'r.domainKey', 'r.email']
            : ['r.sortKey', 'r.displayName', 'r.emailKey', 'r.phone', 'r.mobile'];
    }

    /**
     * Active records of the tenant under the visibility filter, by name.
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        $company = $this->type === SubjectType::Company;
        $qb      = $this->manager()->createQueryBuilder()
            ->select('r.id AS id', 'r.tenantId AS tenantId', 'r.ownerId AS ownerId', 'r.email AS email', 'r.city AS city', ($company ? 'r.name' : 'r.displayName') . ' AS label')
            ->from($company ? Company::class : Contact::class, 'r')
            ->where('r.tenantId = :crm_tenant')->andWhere('r.status = :crm_active')
            ->setParameter('crm_tenant', (string) $tenant)->setParameter('crm_active', RecordStatus::Active)
            ->orderBy($company ? 'r.nameKey' : 'r.sortKey', 'ASC')->addOrderBy('r.id', 'ASC');
        $context = $this->context();
        self::restrict($qb, 'r.ownerId', $context instanceof CrmContext ? $this->access->visibleOwners($context) : []);

        return $qb;
    }

    /**
     * Contacts only: `<company id>` narrows to the contacts affiliated to that active company of the tenant (in SQL);
     * anything else is refused.
     */
    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        if ($this->type !== SubjectType::Contact || !$queryBuilder instanceof OrmQueryBuilder || !RecordId::isValid($scope)) {
            return false;
        }
        $queryBuilder->andWhere('EXISTS (SELECT 1 FROM ' . Affiliation::class . ' af, ' . Company::class . ' co WHERE af.contactId = r.id AND af.tenantId = :crm_tenant AND af.companyId = :crm_scope AND co.id = af.companyId AND co.tenantId = :crm_tenant AND co.status = :crm_active)')
            ->setParameter('crm_scope', $scope);

        return true;
    }

    /**
     * One result: the record's name, its e-mail and city, its page.
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        return new SearchItem(
            self::field($row, 'label') ?? '',
            $this->urls->generate(self::SHOW_ROUTE, ['type' => $this->type->segment(), 'id' => self::field($row, 'id')]),
            self::subtitle([self::field($row, 'email'), self::field($row, 'city')]),
        );
    }

    /**
     * The filtered list of the type.
     */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return SeeAllLink::build($this->urls, self::LIST_ROUTE, ['type' => $this->type->segment()], $query);
    }

    /**
     * The record id of a recent of this group (the record page route of this type).
     */
    public function referenceId(SearchReference $reference): ?string
    {
        return self::referenced($reference, self::SHOW_ROUTE, ['type' => $this->type->segment()]);
    }

    /**
     * The subject of a row.
     */
    protected function subject(array|object $row): SubjectRef
    {
        return SubjectRef::of($this->type, self::field($row, 'id') ?? '', self::field($row, 'ownerId'));
    }
}
