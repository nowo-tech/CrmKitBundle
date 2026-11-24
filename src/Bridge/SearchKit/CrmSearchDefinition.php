<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\SearchKit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function is_string;

/**
 * What the CRM's SearchKit groups share (0.3.0, SearchKit >= 1.9; contract section 12): the tenant and the feature
 * gate of the CRM's access gate, `view` in the tenant (any refusal: no group, no hint), the record-visibility filter in
 * SQL (ownerless records and the visible owners), then `nowo_crm_kit.record_access_check` on every candidate (at most
 * {@see self::maxCandidates()} decisions). Rows are scalar arrays of the bundle's entity manager: no entity is managed
 * and the manager is never cleared. Stateless (worker mode): the access context is resolved on every call.
 */
abstract class CrmSearchDefinition extends EntitySearchDefinition
{
    /** Separator of the subtitle parts. */
    protected const SEPARATOR = ' · ';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        protected readonly CrmAccess $access,
        protected readonly DoctrineTransactionRunner $runner,
        protected readonly UrlGeneratorInterface $urls,
        protected readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The tenant of the call when the CRM is on for it and the person may view it; else null.
     */
    public function tenant(SearchQuery $query): int|string|null
    {
        return $this->context()?->tenant->value;
    }

    /**
     * The gate again, for the tenant SearchKit asks about (the same answer as {@see self::tenant()}).
     */
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->context()?->tenant->value === $tenant;
    }

    /**
     * The candidates the person may view: the tenant and the visible owners re-checked exactly (REQ-SEC-006 rule 2),
     * then the record decision of each ({@see self::subject()}).
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $context = $this->context();
        if (!$context instanceof CrmContext || $context->tenant->value !== $tenant) {
            return [];
        }
        $owners = $this->access->visibleOwners($context);

        return array_values(array_filter($rows, function (array|object $row) use ($context, $owners): bool { // @igor-ignore - array_filter calls this closure at once and keeps nothing
            $owner = self::field($row, 'ownerId');

            return self::field($row, 'tenantId') === $context->tenant->value
                && CrmAccess::visible($owners, $owner)
                && $this->allows($context, $row);
        }));
    }

    /**
     * The id column of revalidated recents.
     */
    public function idField(): ?string
    {
        return 'r.id';
    }

    /**
     * Whether the person may view the row (the record decision with its owner).
     *
     * @param array<string, mixed>|object $row
     */
    protected function allows(CrmContext $context, array|object $row): bool
    {
        return $this->may($context, $this->subject($row));
    }

    /**
     * Whether a permission is held on a subject (a refusal is false).
     */
    protected function may(CrmContext $context, SubjectRef $subject, CrmPermission $permission = CrmPermission::View): bool
    {
        try {
            $this->access->require($context, $permission, $subject);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    /**
     * The subject of a row.
     *
     * @param array<string, mixed>|object $row
     */
    abstract protected function subject(array|object $row): SubjectRef;

    /**
     * The access context of the call, or null when the CRM is not available or `view` is refused.
     */
    protected function context(): ?CrmContext
    {
        try {
            return $this->access->context(CrmPermission::View);
        } catch (NotAvailableException|AccessDeniedException) {
            return null;
        }
    }

    /**
     * The bundle's entity manager (reset first when a failure closed it; never cleared here).
     */
    protected function manager(): EntityManagerInterface
    {
        return $this->runner->manager();
    }

    /**
     * Resource scoping in DQL on an owner column: only ownerless rows and the visible owners' (nothing when every row
     * is visible).
     *
     * @param list<string>|null $owners
     */
    protected static function restrict(QueryBuilder $queryBuilder, string $column, ?array $owners): void
    {
        if ($owners === null) {
            return;
        }
        if ($owners === []) {
            $queryBuilder->andWhere($column . ' IS NULL');

            return;
        }
        $queryBuilder->andWhere($column . ' IS NULL OR ' . $column . ' IN (:crm_owners)')->setParameter('crm_owners', $owners);
    }

    /**
     * A string column of a row, or null.
     *
     * @param array<array-key, mixed>|object $row
     */
    protected static function field(array|object $row, string $name): ?string
    {
        $value = (array) $row;
        $value = $value[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The record id of a recent when its route and parameters are this group's (client input: checked exactly).
     *
     * @param array<string, string> $parameters the fixed parameters of the route
     */
    protected static function referenced(SearchReference $reference, string $route, array $parameters): ?string
    {
        if ($reference->route !== $route) {
            return null;
        }
        foreach ($parameters as $name => $value) {
            if (($reference->routeParameters[$name] ?? null) !== $value) {
                return null;
            }
        }
        $id = $reference->routeParameters['id'] ?? null;

        return is_string($id) && RecordId::isValid($id) && count($reference->routeParameters) === count($parameters) + 1 ? $id : null;
    }

    /**
     * A translated label of the bundle's domain.
     */
    protected function trans(string $key, SearchQuery $query, string $fallback): string
    {
        return $this->translator?->trans($key, [], TranslationDomain::NAME, $query->locale) ?? $fallback;
    }

    /**
     * The parts of a subtitle that are not empty, joined.
     *
     * @param list<string|null> $parts
     */
    protected static function subtitle(array $parts): string
    {
        return implode(self::SEPARATOR, array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
