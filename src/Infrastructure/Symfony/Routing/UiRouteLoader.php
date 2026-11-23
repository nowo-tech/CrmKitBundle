<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Routing;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\AdminController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\ImportController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineAdminController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordPartsController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;

/**
 * Route loader `nowo_crm_kit_ui` (contract section 11.1), only when `ui.enabled` (REQ-UI-001: routes are opt-in); the
 * funnel pages (0.3.0) only with `pipeline.enabled` too. The
 * host imports it with its prefix: `{resource: ., type: nowo_crm_kit_ui}`. Route names `nowo_crm_kit_*`.
 */
final class UiRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_crm_kit_ui';

    /** Route attribute that marks the CRM's routes (conflict formatter, exception listener). */
    public const ROUTE_ATTRIBUTE = '_nowo_crm_kit';

    /**
     * Wires the settings.
     */
    public function __construct(private readonly bool $enabled = false, private readonly string $path = '/crm', private readonly bool $pipeline = false)
    {
        parent::__construct();
    }

    /**
     * The routes (none while the UI is off).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        $base = rtrim($this->path, '/');
        $id   = ['id' => RecordId::REQUIREMENT];
        $seg  = ['type' => SubjectType::SEGMENT_REQUIREMENT];
        $r    = RecordController::class . '::';
        $p    = RecordPartsController::class . '::';
        $a    = AdminController::class . '::';
        $i    = ImportController::class . '::';
        $this->add($routes, 'home', $base === '' ? '/' : $base, $r . 'home', ['GET']);
        $this->add($routes, 'list', $base . '/{type}', $r . 'list', ['GET'], $seg);
        $this->add($routes, 'new', $base . '/{type}/new', $r . 'create', ['GET', 'POST'], $seg);
        $this->add($routes, 'show', $base . '/{type}/{id}', $r . 'show', ['GET'], $seg + $id);
        $this->add($routes, 'edit', $base . '/{type}/{id}/edit', $r . 'edit', ['GET', 'POST'], $seg + $id);
        $this->add($routes, 'trash', $base . '/{type}/{id}/trash', $r . 'trash', ['POST'], $seg + $id);
        $this->add($routes, 'restore', $base . '/{type}/{id}/restore', $r . 'restore', ['POST'], $seg + $id);
        $this->add($routes, 'purge', $base . '/{type}/{id}/purge', $r . 'purge', ['POST'], $seg + $id);
        $this->add($routes, 'merge', $base . '/{type}/{id}/merge', $r . 'merge', ['GET', 'POST'], $seg + $id);
        $this->add($routes, 'export', $base . '/{type}/export.csv', $r . 'export', ['GET'], $seg);
        $this->add($routes, 'note_add', $base . '/{type}/{id}/notes', $p . 'addNote', ['POST'], $seg + $id);
        $this->add($routes, 'note_edit', $base . '/notes/{id}/edit', $p . 'editNote', ['POST'], $id);
        $this->add($routes, 'note_delete', $base . '/notes/{id}/delete', $p . 'deleteNote', ['POST'], $id);
        $this->add($routes, 'tags_assign', $base . '/{type}/{id}/tags', $p . 'assignTags', ['POST'], $seg + $id);
        $this->add($routes, 'link_search', $base . '/{type}/{id}/links/search', $p . 'searchLinks', ['GET'], $seg + $id);
        $this->add($routes, 'link_add', $base . '/{type}/{id}/links', $p . 'addLink', ['POST'], $seg + $id);
        $this->add($routes, 'link_remove', $base . '/links/{id}/remove', $p . 'removeLink', ['POST'], $id);
        $this->add($routes, 'affiliate', $base . '/contacts/{id}/affiliations', $p . 'affiliate', ['POST'], $id);
        $this->add($routes, 'primary_contact_clear', $base . '/companies/{id}/primary-contact/clear', $p . 'primaryContact', ['POST'], $id);
        $this->add($routes, 'primary_contact', $base . '/companies/{id}/primary-contact/{contact}', $p . 'primaryContact', ['POST'], $id + ['contact' => RecordId::REQUIREMENT]);
        $this->add($routes, 'unaffiliate', $base . '/affiliations/{id}/remove', $p . 'unaffiliate', ['POST'], $id);
        $this->add($routes, 'consent', $base . '/contacts/{id}/consent', $p . 'consent', ['POST'], $id);
        $this->add($routes, 'vcard', $base . '/contacts/{id}/vcard', $p . 'vcard', ['GET'], $id);
        $this->add($routes, 'gdpr_export', $base . '/contacts/{id}/gdpr-export', $p . 'gdprExport', ['POST'], $id);
        $this->add($routes, 'erase', $base . '/contacts/{id}/erase', $p . 'erase', ['POST'], $id);
        $this->add($routes, 'import', $base . '/import', $i . 'index', ['GET', 'POST']);
        $this->add($routes, 'import_show', $base . '/import/{id}', $i . 'show', ['GET', 'POST'], $id);
        $this->add($routes, 'tags', $base . '/admin/tags', $a . 'tags', ['GET', 'POST']);
        $this->add($routes, 'tag_edit', $base . '/admin/tags/{id}', $a . 'tag', ['GET', 'POST'], $id);
        $this->add($routes, 'fields', $base . '/admin/fields', $a . 'fields', ['GET', 'POST']);
        $this->add($routes, 'field_edit', $base . '/admin/fields/{id}', $a . 'field', ['GET', 'POST'], $id);
        $this->add($routes, 'settings', $base . '/admin/settings', $a . 'settings', ['GET', 'POST']);
        if ($this->pipeline) {
            $this->pipeline($routes, $base, $id);
        }

        return $routes;
    }

    /**
     * The funnel pages (0.3.0, `pipeline.enabled`).
     *
     * @param array<string, string> $id
     */
    private function pipeline(RouteCollection $routes, string $base, array $id): void
    {
        $d = PipelineController::class . '::';
        $f = PipelineAdminController::class . '::';
        $this->add($routes, 'deals', $base . '/deals', $d . 'list', ['GET']);
        $this->add($routes, 'deal_new', $base . '/deals/new', $d . 'create', ['GET', 'POST']);
        $this->add($routes, 'deal_show', $base . '/deals/{id}', $d . 'show', ['GET'], $id);
        $this->add($routes, 'deal_edit', $base . '/deals/{id}/edit', $d . 'edit', ['GET', 'POST'], $id);
        $this->add($routes, 'deal_move', $base . '/deals/{id}/stage', $d . 'move', ['POST'], $id);
        $this->add($routes, 'deal_trash', $base . '/deals/{id}/trash', $d . 'trash', ['POST'], $id);
        $this->add($routes, 'deal_restore', $base . '/deals/{id}/restore', $d . 'restore', ['POST'], $id);
        $this->add($routes, 'deal_purge', $base . '/deals/{id}/purge', $d . 'purge', ['POST'], $id);
        $this->add($routes, 'deal_board', $base . '/funnels/{id}/board', $d . 'board', ['GET'], $id);
        $this->add($routes, 'next_action_add', $base . '/{type}/{id}/next-actions', $d . 'addNextAction', ['POST'], ['type' => 'companies|contacts|deals'] + $id);
        $this->add($routes, 'next_action_close', $base . '/next-actions/{id}/{action}', $d . 'closeNextAction', ['POST'], $id + ['action' => 'done|cancel']);
        $this->add($routes, 'lifecycle', $base . '/{type}/{id}/lifecycle', $d . 'lifecycle', ['POST'], ['type' => SubjectType::SEGMENT_REQUIREMENT] + $id);
        $this->add($routes, 'funnel_stats', $base . '/funnels/{id}/stats', $f . 'stats', ['GET'], $id);
        $this->add($routes, 'funnel_stats_csv', $base . '/funnels/{id}/stats.csv', $f . 'statsCsv', ['GET'], $id);
        $this->add($routes, 'funnels', $base . '/admin/funnels', $f . 'funnels', ['GET', 'POST']);
        $this->add($routes, 'funnel_edit', $base . '/admin/funnels/{id}', $f . 'funnel', ['GET', 'POST'], $id);
        $this->add($routes, 'stage_edit', $base . '/admin/stages/{id}', $f . 'stage', ['POST'], $id);
        $this->add($routes, 'stage_move', $base . '/admin/stages/{id}/move/{direction}', $f . 'moveStage', ['POST'], $id + ['direction' => 'up|down']);
    }

    /**
     * Adds one route, marked as the CRM's.
     *
     * @param list<string> $methods
     * @param array<string, string> $requirements
     */
    private function add(RouteCollection $routes, string $name, string $path, string $controller, array $methods, array $requirements = []): void
    {
        $routes->add('nowo_crm_kit_' . $name, new Route($path, ['_controller' => $controller, self::ROUTE_ATTRIBUTE => true], $requirements, [], '', [], $methods));
    }

    /**
     * Whether the loader handles the resource.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
