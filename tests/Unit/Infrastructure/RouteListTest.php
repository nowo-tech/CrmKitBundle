<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiRouteLoader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\RouteCollection;

use function class_exists;
use function explode;
use function implode;
use function is_string;

/**
 * REQ-SF-004 rule 8 (programmatic route loaders): the routes the two loaders generate, by name, methods and path, so a
 * renamed route or a moved path fails here (rule 5); and no controller behind them carries `#[Route]`, so the host's
 * `routing.controllers` import cannot load a CRM route without the documented `nowo_crm_kit_ui` / `nowo_crm_kit_api`
 * import (rule 7).
 */
final class RouteListTest extends TestCase
{
    public function testTheUiRouteList(): void
    {
        self::assertSame([
            'nowo_crm_kit_home'                  => 'GET /crm',
            'nowo_crm_kit_list'                  => 'GET /crm/{type}',
            'nowo_crm_kit_new'                   => 'GET|POST /crm/{type}/new',
            'nowo_crm_kit_show'                  => 'GET /crm/{type}/{id}',
            'nowo_crm_kit_edit'                  => 'GET|POST /crm/{type}/{id}/edit',
            'nowo_crm_kit_trash'                 => 'POST /crm/{type}/{id}/trash',
            'nowo_crm_kit_restore'               => 'POST /crm/{type}/{id}/restore',
            'nowo_crm_kit_purge'                 => 'POST /crm/{type}/{id}/purge',
            'nowo_crm_kit_merge'                 => 'GET|POST /crm/{type}/{id}/merge',
            'nowo_crm_kit_export'                => 'GET /crm/{type}/export.csv',
            'nowo_crm_kit_note_add'              => 'POST /crm/{type}/{id}/notes',
            'nowo_crm_kit_note_edit'             => 'POST /crm/notes/{id}/edit',
            'nowo_crm_kit_note_delete'           => 'POST /crm/notes/{id}/delete',
            'nowo_crm_kit_tags_assign'           => 'POST /crm/{type}/{id}/tags',
            'nowo_crm_kit_link_search'           => 'GET /crm/{type}/{id}/links/search',
            'nowo_crm_kit_link_add'              => 'POST /crm/{type}/{id}/links',
            'nowo_crm_kit_link_remove'           => 'POST /crm/links/{id}/remove',
            'nowo_crm_kit_affiliate'             => 'POST /crm/contacts/{id}/affiliations',
            'nowo_crm_kit_primary_contact_clear' => 'POST /crm/companies/{id}/primary-contact/clear',
            'nowo_crm_kit_primary_contact'       => 'POST /crm/companies/{id}/primary-contact/{contact}',
            'nowo_crm_kit_unaffiliate'           => 'POST /crm/affiliations/{id}/remove',
            'nowo_crm_kit_consent'               => 'POST /crm/contacts/{id}/consent',
            'nowo_crm_kit_vcard'                 => 'GET /crm/contacts/{id}/vcard',
            'nowo_crm_kit_gdpr_export'           => 'POST /crm/contacts/{id}/gdpr-export',
            'nowo_crm_kit_erase'                 => 'POST /crm/contacts/{id}/erase',
            'nowo_crm_kit_import'                => 'GET|POST /crm/import',
            'nowo_crm_kit_import_show'           => 'GET|POST /crm/import/{id}',
            'nowo_crm_kit_tags'                  => 'GET|POST /crm/admin/tags',
            'nowo_crm_kit_tag_edit'              => 'GET|POST /crm/admin/tags/{id}',
            'nowo_crm_kit_fields'                => 'GET|POST /crm/admin/fields',
            'nowo_crm_kit_field_edit'            => 'GET|POST /crm/admin/fields/{id}',
            'nowo_crm_kit_settings'              => 'GET|POST /crm/admin/settings',
            'nowo_crm_kit_deals'                 => 'GET /crm/deals',
            'nowo_crm_kit_deal_new'              => 'GET|POST /crm/deals/new',
            'nowo_crm_kit_deal_show'             => 'GET /crm/deals/{id}',
            'nowo_crm_kit_deal_edit'             => 'GET|POST /crm/deals/{id}/edit',
            'nowo_crm_kit_deal_move'             => 'GET|POST /crm/deals/{id}/stage',
            'nowo_crm_kit_deal_trash'            => 'GET|POST /crm/deals/{id}/trash',
            'nowo_crm_kit_deal_restore'          => 'POST /crm/deals/{id}/restore',
            'nowo_crm_kit_deal_purge'            => 'GET|POST /crm/deals/{id}/purge',
            'nowo_crm_kit_deal_board'            => 'GET /crm/funnels/{id}/board',
            'nowo_crm_kit_next_action_add'       => 'GET|POST /crm/{type}/{id}/next-actions',
            'nowo_crm_kit_next_action_close'     => 'POST /crm/next-actions/{id}/{action}',
            'nowo_crm_kit_lifecycle'             => 'GET|POST /crm/{type}/{id}/lifecycle',
            'nowo_crm_kit_funnel_stats'          => 'GET /crm/funnels/{id}/stats',
            'nowo_crm_kit_funnel_stats_csv'      => 'GET /crm/funnels/{id}/stats.csv',
            'nowo_crm_kit_funnels'               => 'GET|POST /crm/admin/funnels',
            'nowo_crm_kit_funnel_new'            => 'GET|POST /crm/admin/funnels/new',
            'nowo_crm_kit_funnel_edit'           => 'GET|POST /crm/admin/funnels/{id}',
            'nowo_crm_kit_stage_new'             => 'GET|POST /crm/admin/funnels/{id}/stages/new',
            'nowo_crm_kit_stage_edit'            => 'GET|POST /crm/admin/stages/{id}',
            'nowo_crm_kit_stage_move'            => 'POST /crm/admin/stages/{id}/move/{direction}',
        ], $this->describe((new UiRouteLoader(true, '/crm', true))->load('.')));
    }

    public function testTheUiRouteListWithoutThePipeline(): void
    {
        $routes = $this->describe((new UiRouteLoader(true, '/crm', false))->load('.'));
        self::assertCount(32, $routes);
        self::assertArrayNotHasKey('nowo_crm_kit_deals', $routes);
        self::assertSame('GET /crm', $routes['nowo_crm_kit_home']);
    }

    public function testTheApiRouteList(): void
    {
        self::assertSame([
            'nowo_crm_kit_api_identity'            => 'GET /api/crm/me',
            'nowo_crm_kit_api_search'              => 'GET /api/crm/search',
            'nowo_crm_kit_api_companies_list'      => 'GET /api/crm/companies',
            'nowo_crm_kit_api_contacts_list'       => 'GET /api/crm/contacts',
            'nowo_crm_kit_api_company_get'         => 'GET /api/crm/companies/{id}',
            'nowo_crm_kit_api_contact_get'         => 'GET /api/crm/contacts/{id}',
            'nowo_crm_kit_api_company_create'      => 'POST /api/crm/companies',
            'nowo_crm_kit_api_contact_create'      => 'POST /api/crm/contacts',
            'nowo_crm_kit_api_company_update'      => 'PATCH /api/crm/companies/{id}',
            'nowo_crm_kit_api_contact_update'      => 'PATCH /api/crm/contacts/{id}',
            'nowo_crm_kit_api_company_trash'       => 'DELETE /api/crm/companies/{id}',
            'nowo_crm_kit_api_contact_trash'       => 'DELETE /api/crm/contacts/{id}',
            'nowo_crm_kit_api_company_contacts'    => 'GET /api/crm/companies/{id}/contacts',
            'nowo_crm_kit_api_contact_companies'   => 'GET /api/crm/contacts/{id}/companies',
            'nowo_crm_kit_api_affiliation_set'     => 'PUT /api/crm/contacts/{contactId}/companies/{companyId}',
            'nowo_crm_kit_api_affiliation_remove'  => 'DELETE /api/crm/contacts/{contactId}/companies/{companyId}',
            'nowo_crm_kit_api_company_timeline'    => 'GET /api/crm/companies/{id}/timeline',
            'nowo_crm_kit_api_contact_timeline'    => 'GET /api/crm/contacts/{id}/timeline',
            'nowo_crm_kit_api_deal_timeline'       => 'GET /api/crm/deals/{id}/timeline',
            'nowo_crm_kit_api_company_notes'       => 'GET /api/crm/companies/{id}/notes',
            'nowo_crm_kit_api_contact_notes'       => 'GET /api/crm/contacts/{id}/notes',
            'nowo_crm_kit_api_company_note_add'    => 'POST /api/crm/companies/{id}/notes',
            'nowo_crm_kit_api_contact_note_add'    => 'POST /api/crm/contacts/{id}/notes',
            'nowo_crm_kit_api_company_tag_add'     => 'PUT /api/crm/companies/{id}/tags/{tagId}',
            'nowo_crm_kit_api_contact_tag_add'     => 'PUT /api/crm/contacts/{id}/tags/{tagId}',
            'nowo_crm_kit_api_company_tag_remove'  => 'DELETE /api/crm/companies/{id}/tags/{tagId}',
            'nowo_crm_kit_api_contact_tag_remove'  => 'DELETE /api/crm/contacts/{id}/tags/{tagId}',
            'nowo_crm_kit_api_tags'                => 'GET /api/crm/tags',
            'nowo_crm_kit_api_company_links'       => 'GET /api/crm/companies/{id}/links',
            'nowo_crm_kit_api_contact_links'       => 'GET /api/crm/contacts/{id}/links',
            'nowo_crm_kit_api_contact_consents'    => 'GET /api/crm/contacts/{id}/consents',
            'nowo_crm_kit_api_company_next_action' => 'POST /api/crm/companies/{id}/next-actions',
            'nowo_crm_kit_api_contact_next_action' => 'POST /api/crm/contacts/{id}/next-actions',
            'nowo_crm_kit_api_deal_next_action'    => 'POST /api/crm/deals/{id}/next-actions',
            'nowo_crm_kit_api_funnels'             => 'GET /api/crm/funnels',
            'nowo_crm_kit_api_funnel_stats'        => 'GET /api/crm/funnels/{id}/stats',
            'nowo_crm_kit_api_deal_search'         => 'GET /api/crm/deals',
            'nowo_crm_kit_api_deal_get'            => 'GET /api/crm/deals/{id}',
            'nowo_crm_kit_api_deal_create'         => 'POST /api/crm/deals',
            'nowo_crm_kit_api_deal_update'         => 'PATCH /api/crm/deals/{id}',
            'nowo_crm_kit_api_deal_move_stage'     => 'POST /api/crm/deals/{id}/stage',
            'nowo_crm_kit_api_deal_trash'          => 'DELETE /api/crm/deals/{id}',
        ], $this->describe((new CrmApiRouteLoader(true, '/api/crm', true))->load('.')));
    }

    public function testNoControllerCarriesARouteAttribute(): void
    {
        $routes = (new UiRouteLoader(true, '/crm', true))->load('.');
        $routes->addCollection((new CrmApiRouteLoader(true, '/api/crm', true))->load('.'));
        $classes = [];
        foreach ($routes->all() as $route) {
            $controller = $route->getDefault('_controller');
            self::assertTrue(is_string($controller));
            $classes[explode('::', $controller)[0]] = true;
        }
        self::assertNotEmpty($classes);
        foreach ($classes as $class => $unused) {
            self::assertTrue(class_exists($class), $class);
            $reflection = new ReflectionClass($class);
            self::assertSame([], $reflection->getAttributes(RouteAttribute::class), $class);
            foreach ($reflection->getMethods() as $method) {
                self::assertSame([], $method->getAttributes(RouteAttribute::class), $class . '::' . $method->getName());
            }
        }
    }

    /**
     * @return array<string, string> route name => "METHODS path"
     */
    private function describe(RouteCollection $routes): array
    {
        $list = [];
        foreach ($routes->all() as $name => $route) {
            $list[$name] = implode('|', $route->getMethods()) . ' ' . $route->getPath();
        }

        return $list;
    }
}
