<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotList;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotSchemaView;
use Nowo\CrmKitBundle\Domain\Service\HubSpotConnectionServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HubSpotImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HubSpotConnectType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HubSpotMappingType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HubSpotStartType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ImportDialog;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_values;
use function is_array;
use function is_numeric;
use function is_string;
use function str_starts_with;
use function substr;

/**
 * The HubSpot source of the import (0.10.0, design section 13): the connection card, connect / replace (`import` +
 * `manage`), disconnect, the start step and the mapping step, then the import page of the CSV flow (progress,
 * "Continue", cancel). Every denial answers 404 (tenant, feature, actor, permission; the routes do not exist while
 * HubSpot is off). Each page is also a step fragment of the import modal ({@see ImportDialog}), loaded on demand. The
 * credential field is a password input, never pre-filled or re-rendered; no HubSpot text but labels and list names
 * (escaped) is shown. Stateless.
 */
final readonly class HubSpotImportController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private HubSpotConnectionServiceInterface $connections,
        private HubSpotImportServiceInterface $imports,
        private ImportServiceInterface $runs,
        private int $syncChunks = 5,
    ) {
    }

    /**
     * The connection card.
     */
    public function index(Request $request): Response
    {
        return $this->hidden(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Import);
            $connection = $this->connections->status();
            $action     = $this->ui->form(ActionType::class, ['version' => (string) ($connection->version ?? 0)], ['versioned' => true], 'crm_action');

            return $this->respond($request, 'source', Response::HTTP_OK, ['connection' => $connection, 'action' => $action->createView(), 'manage' => $this->ui->can(CrmPermission::Manage)]);
        });
    }

    /**
     * Connect or replace the credential.
     */
    public function connect(Request $request): Response
    {
        return $this->hidden(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Import);
            $this->ui->require(CrmPermission::Manage);
            $current = $this->connections->status();
            $form    = $this->ui->form(HubSpotConnectType::class, ['version' => $current === null ? '' : (string) $current->version, 'label' => $current?->label], [], 'crm_hubspot_connect');
            $form->handleRequest($request);
            $status = Response::HTTP_OK;
            $alert  = null;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    $data = $this->data($form);
                    try {
                        $this->connections->connect(is_string($data['token'] ?? null) ? $data['token'] : '', is_string($data['label'] ?? null) ? $data['label'] : null, is_numeric($data['version'] ?? null) ? (int) $data['version'] : null, ($data['confirm_account_change'] ?? false) === true);
                        if (!ImportDialog::requested($request)) {
                            $this->ui->flash($request, 'success', 'import.hubspot.connected');
                        }

                        return $this->next($request, 'nowo_crm_kit_hubspot');
                    } catch (InvalidInputException $e) {
                        $this->ui->errors($form, $e);
                    } catch (ConflictException $e) {
                        $status = Response::HTTP_CONFLICT;
                        $alert  = $this->ui->trans('flash.conflict.' . $e->errorCode);
                    }
                }
            }

            return $this->respond($request, 'connect', $status, ['form' => $form->createView(), 'connection' => $current, 'alert' => $alert]);
        });
    }

    /**
     * Remove the credential.
     */
    public function disconnect(Request $request): Response
    {
        return $this->hidden(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Import);
            $this->ui->require(CrmPermission::Manage);
            $action = $this->ui->form(ActionType::class, null, ['versioned' => true], 'crm_action');
            $action->handleRequest($request);
            $alert = 'error.csrf';
            if ($action->isSubmitted() && $action->isValid()) {
                $data = $this->data($action);
                try {
                    $this->connections->disconnect(is_numeric($data['version'] ?? null) ? (int) $data['version'] : -1);
                    if (!ImportDialog::requested($request)) {
                        $this->ui->flash($request, 'success', 'import.hubspot.disconnected');
                    }

                    return $this->next($request, 'nowo_crm_kit_hubspot');
                } catch (ConflictException $e) {
                    $alert = 'flash.conflict.' . $e->errorCode;
                }
            }
            $connection = $this->connections->status();
            $fresh      = $this->ui->form(ActionType::class, ['version' => (string) ($connection->version ?? 0)], ['versioned' => true], 'crm_action');

            return $this->respond($request, 'source', Response::HTTP_CONFLICT, ['connection' => $connection, 'action' => $fresh->createView(), 'manage' => true, 'alert' => $this->ui->trans($alert)]);
        });
    }

    /**
     * The start step: objects, lists and duplicate policy ⇒ an import in preview.
     */
    public function start(Request $request): Response
    {
        return $this->hidden(function () use ($request): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Import);
            $alert = null;
            $lists = [];
            try {
                foreach ($this->imports->lists() as $list) {
                    $lists[$this->listLabel($list)] = $list->id->value;
                }
            } catch (InvalidInputException $e) {
                $alert = $this->firstError($e);
            }
            $form = $this->ui->form(HubSpotStartType::class, ['objects' => ['companies', 'contacts'], 'policy' => 'skip'], ['lists' => $lists], 'crm_hubspot_start');
            $form->handleRequest($request);
            $status = $alert === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    $data = $this->data($form);
                    try {
                        $id = $this->imports->start($this->scope($data), DuplicatePolicy::tryFrom(is_string($data['policy'] ?? null) ? $data['policy'] : '') ?? DuplicatePolicy::Skip);

                        return $this->next($request, 'nowo_crm_kit_hubspot_mapping', ['id' => $id]);
                    } catch (InvalidInputException $e) {
                        $this->ui->errors($form, $e);
                        $alert = isset($e->errors['_']) ? $this->firstError($e) : $alert;
                    } catch (ConflictException $e) {
                        $status = Response::HTTP_CONFLICT;
                        $alert  = $this->ui->trans('flash.conflict.' . $e->errorCode);
                    }
                }
            }

            return $this->respond($request, 'start', $status, ['form' => $form->createView(), 'alert' => $alert]);
        });
    }

    /**
     * The mapping step, then the confirm and the first pages of the run.
     */
    public function mapping(Request $request, string $id): Response
    {
        return $this->hidden(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->ui->require(CrmPermission::Import);
            try {
                $schema = $this->imports->schema($id);
            } catch (ConflictException) {
                // Confirmed meanwhile: its progress is on the import page.
                return $this->next($request, 'nowo_crm_kit_import_show', ['id' => $id]);
            } catch (InvalidInputException $e) {
                return $this->respond($request, 'mapping', Response::HTTP_UNPROCESSABLE_ENTITY, ['form' => null, 'alert' => $this->firstError($e), 'importId' => $id]);
            }
            [$rows, $names, $data] = $this->rows($schema);
            $form                  = $this->ui->form(HubSpotMappingType::class, $data + ['policy' => 'skip', 'version' => (string) $schema->version], ['rows' => $rows], 'crm_hubspot_mapping');
            $form->handleRequest($request);
            $status = Response::HTTP_OK;
            $alert  = null;
            if ($form->isSubmitted()) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                if ($form->isValid()) {
                    $values = $this->data($form);
                    try {
                        $this->imports->confirm($id, $this->mappingOf($values, $names), DuplicatePolicy::tryFrom(is_string($values['policy'] ?? null) ? $values['policy'] : '') ?? DuplicatePolicy::Skip, is_numeric($values['version'] ?? null) ? (int) $values['version'] : -1);
                        $this->runs->run($id, $this->syncChunks);

                        return $this->next($request, 'nowo_crm_kit_import_show', ['id' => $id]);
                    } catch (InvalidInputException $e) {
                        $alert = $this->firstError($e);
                    } catch (ConflictException $e) {
                        $status = Response::HTTP_CONFLICT;
                        $alert  = $this->ui->trans('flash.conflict.' . $e->errorCode);
                    }
                }
            }

            return $this->respond($request, 'mapping', $status, ['form' => $form->createView(), 'alert' => $alert, 'importId' => $id, 'schema' => $schema]);
        });
    }

    /**
     * Runs a page; every denial and every unknown id is a 404.
     *
     * @param callable(): Response $page
     */
    private function hidden(callable $page): Response
    {
        try {
            return $page();
        } catch (NotAvailableException|AccessDeniedException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        }
    }

    /**
     * The selects of the mapping step, the property or list each select names, and the current choices.
     *
     * @return array{list<array{name: string, label: string, choices: array<string, string>}>, array<string, array{string, string}>, array<string, string>}
     */
    private function rows(HubSpotSchemaView $schema): array
    {
        $rows  = [];
        $names = [];
        $data  = [];
        foreach ($schema->properties as $object => $properties) {
            foreach ($properties as $i => $property) {
                $name    = 'p_' . $object . '_' . $i;
                $choices = [];
                foreach ($property->targets as $target) {
                    $choices[$this->targetLabel($target)] = $target;
                }
                $rows[]       = ['name' => $name, 'label' => $this->ui->trans('import.hubspot.object.' . $object) . ' · ' . $property->label . ' (' . $property->name . ')', 'choices' => $choices];
                $names[$name] = [(string) $object, $property->name];
                $data[$name]  = $property->target;
            }
        }
        $tags = [$this->ui->trans('import.hubspot.list.skip') => '', $this->ui->trans('import.hubspot.list.create') => HubSpotMapping::CREATE];
        foreach ($schema->tags as $tag) {
            $tags[$tag->name] = $tag->id;
        }
        foreach ($schema->lists as $i => $row) {
            $name         = 'l_' . $i;
            $rows[]       = ['name' => $name, 'label' => $this->ui->trans('import.hubspot.list.label', ['%name%' => $this->listLabel($row['list'])]), 'choices' => $tags];
            $names[$name] = ['list', $row['list']->id->value];
            $data[$name]  = $row['tag'];
        }

        return [$rows, $names, $data];
    }

    /**
     * The mapping of a valid mapping form (skipped properties and lists left out).
     *
     * @param array<string, mixed> $values
     * @param array<string, array{string, string}> $names
     */
    private function mappingOf(array $values, array $names): HubSpotMapping
    {
        $properties = [];
        $lists      = [];
        foreach ($names as $field => [$scope, $key]) {
            $target = is_string($values[$field] ?? null) ? $values[$field] : '';
            if ($target === '') {
                continue;
            }
            if ($scope === 'list') {
                $lists[$key] = $target;
            } else {
                $properties[$scope][$key] = $target;
            }
        }

        return new HubSpotMapping($properties, $lists);
    }

    /**
     * The scope of a valid start form.
     *
     * @param array<string, mixed> $data
     */
    private function scope(array $data): HubSpotImportScope
    {
        $objects = [];
        foreach (is_array($data['objects'] ?? null) ? $data['objects'] : [] as $object) {
            $kind = is_string($object) ? HubSpotObject::tryFrom($object) : null;
            if ($kind instanceof HubSpotObject) {
                $objects[] = $kind;
            }
        }
        $lists = [];
        foreach (is_array($data['lists'] ?? null) ? $data['lists'] : [] as $list) {
            if (is_string($list) && HubSpotObjectId::isValid($list)) {
                $lists[] = new HubSpotObjectId($list);
            }
        }

        return new HubSpotImportScope($objects, $lists);
    }

    /**
     * The shown label of a target.
     */
    private function targetLabel(string $target): string
    {
        return match (true) {
            $target === ''                      => $this->ui->trans('import.target.skip'),
            $target === 'owner'                 => $this->ui->trans('import.hubspot.target.owner'),
            $target === 'domain'                => $this->ui->trans('import.hubspot.target.domain'),
            str_starts_with($target, 'custom.') => $this->ui->trans('import.hubspot.target.custom', ['%key%' => substr($target, 7)]),
            default                             => $this->ui->trans('field.' . $target),
        };
    }

    /**
     * A list as offered: its name, its kind and size.
     */
    private function listLabel(HubSpotList $list): string
    {
        return $this->ui->trans('import.hubspot.list.option', ['%name%' => $list->name, '%kind%' => $this->ui->trans('import.hubspot.object.' . ($list->object->value ?? 'contacts')), '%size%' => (string) $list->size, '%id%' => $list->id->value]);
    }

    /**
     * The translated first error of an input error.
     */
    private function firstError(InvalidInputException $e): string
    {
        return $this->ui->trans(array_values($e->errors)[0] ?? 'error.invalid', $e->params);
    }

    /**
     * A form's array data.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>
     */
    private function data(FormInterface $form): array
    {
        /** @var array<string, mixed>|null $data */
        $data = $form->getData();

        return is_array($data) ? $data : [];
    }

    /**
     * After a write: the page's 303 redirect, or the modal's 204 with the next step's address.
     *
     * @param array<string, string> $params
     */
    private function next(Request $request, string $route, array $params = []): Response
    {
        return ImportDialog::requested($request) ? ImportDialog::next($this->ui->url($route, $params)) : $this->ui->redirect($route, $params);
    }

    /**
     * The page of a step, or the modal's fragment of it.
     *
     * @param array<string, mixed> $context
     */
    private function respond(Request $request, string $step, int $status, array $context): Response
    {
        $context = ['step' => $step] + $context + ['form' => null, 'action' => null, 'connection' => null, 'alert' => null, 'manage' => false, 'importId' => null, 'schema' => null];
        if (ImportDialog::requested($request)) {
            return $this->ui->render('@NowoCrmKit/import/hubspot/dialog.html.twig', $context, $status, ImportDialog::fragmentHeaders());
        }

        return $this->ui->render('@NowoCrmKit/import/hubspot/page.html.twig', $context + [
            'page' => new FormPage(
                new PageHeader(UiSupport::label('import.hubspot.title'), UiSupport::label('import.hubspot.step.' . $step), [], tabs: $this->ui->tabs('import')),
                null,
                UiSupport::label('import.hubspot.title'),
                bundle: UiSupport::DOMAIN,
                layout: $this->ui->layout(),
                attributes: ['data-testid' => 'crm-hubspot', 'data-step' => $step],
            ),
        ], $status, ImportDialog::privateHeaders());
    }
}
