<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\Controller\CrmApiController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function str_starts_with;

/**
 * Route type `nowo_crm_kit_api` (0.3.0): the `/crm/v1` JSON API under `api.http.prefix` (the loader adds the full
 * base, `nowo_crm_kit.api_path`; the host passes no prefix). Empty while `api.http.enabled` is false; the deal and
 * funnel routes only with `pipeline.enabled`. Stateless.
 */
final class CrmApiRouteLoader extends Loader
{
    /** Route type. */
    public const TYPE = 'nowo_crm_kit_api';

    /** Request attribute set on every API route. */
    public const ROUTE_ATTRIBUTE = '_nowo_crm_kit_api';

    /** Request attribute naming the operation. */
    public const OPERATION_ATTRIBUTE = '_nowo_crm_kit_api_operation';

    /**
     * Every route: name suffix => [method, path, controller action, operation, extra defaults].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: CrmApiOperation, 4?: array<string, string>}>
     */
    public const ROUTES = [
        'identity'            => ['GET', '/me', 'identity', CrmApiOperation::Identity],
        'search'              => ['GET', '/search', 'search', CrmApiOperation::Search],
        'companies_list'      => ['GET', '/companies', 'search', CrmApiOperation::Search, ['types' => 'company']],
        'contacts_list'       => ['GET', '/contacts', 'search', CrmApiOperation::Search, ['types' => 'contact']],
        'company_get'         => ['GET', '/companies/{id}', 'record', CrmApiOperation::CompanyGet, ['type' => 'company']],
        'contact_get'         => ['GET', '/contacts/{id}', 'record', CrmApiOperation::ContactGet, ['type' => 'contact']],
        'company_create'      => ['POST', '/companies', 'create', CrmApiOperation::CompanyCreate, ['type' => 'company']],
        'contact_create'      => ['POST', '/contacts', 'create', CrmApiOperation::ContactCreate, ['type' => 'contact']],
        'company_update'      => ['PATCH', '/companies/{id}', 'update', CrmApiOperation::CompanyUpdate, ['type' => 'company']],
        'contact_update'      => ['PATCH', '/contacts/{id}', 'update', CrmApiOperation::ContactUpdate, ['type' => 'contact']],
        'company_trash'       => ['DELETE', '/companies/{id}', 'trash', CrmApiOperation::RecordTrash, ['type' => 'company']],
        'contact_trash'       => ['DELETE', '/contacts/{id}', 'trash', CrmApiOperation::RecordTrash, ['type' => 'contact']],
        'company_contacts'    => ['GET', '/companies/{id}/contacts', 'affiliations', CrmApiOperation::AffiliationsList, ['type' => 'company']],
        'contact_companies'   => ['GET', '/contacts/{id}/companies', 'affiliations', CrmApiOperation::AffiliationsList, ['type' => 'contact']],
        'affiliation_set'     => ['PUT', '/contacts/{contactId}/companies/{companyId}', 'setAffiliation', CrmApiOperation::AffiliationSet],
        'affiliation_remove'  => ['DELETE', '/contacts/{contactId}/companies/{companyId}', 'removeAffiliation', CrmApiOperation::AffiliationRemove],
        'company_timeline'    => ['GET', '/companies/{id}/timeline', 'timeline', CrmApiOperation::Timeline, ['type' => 'company']],
        'contact_timeline'    => ['GET', '/contacts/{id}/timeline', 'timeline', CrmApiOperation::Timeline, ['type' => 'contact']],
        'deal_timeline'       => ['GET', '/deals/{id}/timeline', 'timeline', CrmApiOperation::Timeline, ['type' => 'deal']],
        'company_notes'       => ['GET', '/companies/{id}/notes', 'notes', CrmApiOperation::NotesList, ['type' => 'company']],
        'contact_notes'       => ['GET', '/contacts/{id}/notes', 'notes', CrmApiOperation::NotesList, ['type' => 'contact']],
        'company_note_add'    => ['POST', '/companies/{id}/notes', 'addNote', CrmApiOperation::NoteAdd, ['type' => 'company']],
        'contact_note_add'    => ['POST', '/contacts/{id}/notes', 'addNote', CrmApiOperation::NoteAdd, ['type' => 'contact']],
        'company_tag_add'     => ['PUT', '/companies/{id}/tags/{tagId}', 'tag', CrmApiOperation::TagAdd, ['type' => 'company']],
        'contact_tag_add'     => ['PUT', '/contacts/{id}/tags/{tagId}', 'tag', CrmApiOperation::TagAdd, ['type' => 'contact']],
        'company_tag_remove'  => ['DELETE', '/companies/{id}/tags/{tagId}', 'tag', CrmApiOperation::TagRemove, ['type' => 'company']],
        'contact_tag_remove'  => ['DELETE', '/contacts/{id}/tags/{tagId}', 'tag', CrmApiOperation::TagRemove, ['type' => 'contact']],
        'tags'                => ['GET', '/tags', 'tags', CrmApiOperation::TagsList],
        'company_links'       => ['GET', '/companies/{id}/links', 'links', CrmApiOperation::LinksList, ['type' => 'company']],
        'contact_links'       => ['GET', '/contacts/{id}/links', 'links', CrmApiOperation::LinksList, ['type' => 'contact']],
        'contact_consents'    => ['GET', '/contacts/{id}/consents', 'consents', CrmApiOperation::ConsentsGet],
        'company_next_action' => ['POST', '/companies/{id}/next-actions', 'nextAction', CrmApiOperation::NextActionSet, ['type' => 'company']],
        'contact_next_action' => ['POST', '/contacts/{id}/next-actions', 'nextAction', CrmApiOperation::NextActionSet, ['type' => 'contact']],
        'deal_next_action'    => ['POST', '/deals/{id}/next-actions', 'nextAction', CrmApiOperation::NextActionSet, ['type' => 'deal']],
        'funnels'             => ['GET', '/funnels', 'funnels', CrmApiOperation::FunnelsList],
        'funnel_stats'        => ['GET', '/funnels/{id}/stats', 'stats', CrmApiOperation::FunnelStats],
        'deal_search'         => ['GET', '/deals', 'dealSearch', CrmApiOperation::DealSearch],
        'deal_get'            => ['GET', '/deals/{id}', 'deal', CrmApiOperation::DealGet],
        'deal_create'         => ['POST', '/deals', 'dealCreate', CrmApiOperation::DealCreate],
        'deal_update'         => ['PATCH', '/deals/{id}', 'dealUpdate', CrmApiOperation::DealUpdate],
        'deal_move_stage'     => ['POST', '/deals/{id}/stage', 'dealMove', CrmApiOperation::DealMoveStage],
        'deal_trash'          => ['DELETE', '/deals/{id}', 'dealTrash', CrmApiOperation::DealTrash],
    ];

    /** Route name prefix. */
    public const NAME_PREFIX = 'nowo_crm_kit_api_';

    /**
     * @param string $path `nowo_crm_kit.api_path`, e.g. `/api/crm/v1`
     */
    public function __construct(private readonly bool $enabled, private readonly string $path, private readonly bool $pipeline = false)
    {
        parent::__construct();
    }

    /**
     * Whether a route of the table needs the funnels.
     */
    public static function needsPipeline(string $path, CrmApiOperation $operation): bool
    {
        return $operation->pipeline() || str_starts_with($path, '/deals');
    }

    /**
     * The routes.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        foreach (self::ROUTES as $name => $route) {
            [$method, $path, $action, $operation] = $route;
            if (!$this->pipeline && self::needsPipeline($path, $operation)) {
                continue;
            }
            $defaults = ['_controller' => CrmApiController::class . '::' . $action, '_format' => 'json', self::ROUTE_ATTRIBUTE => true, self::OPERATION_ATTRIBUTE => $operation->value] + ($route[4] ?? []);
            $routes->add(self::NAME_PREFIX . $name, new Route($this->path . $path, $defaults, [], [], '', [], [$method]));
        }

        return $routes;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
