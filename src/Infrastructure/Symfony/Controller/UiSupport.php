<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\CrmKitBundle\Domain\Value\HostTypeLabel;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * What every CRM page shares (contract section 11): rendering, the area navigation, flashes, the 404 / 403 mapping
 * of the gate, form errors from the domain's input errors, the CSRF forms. Stateless.
 *
 * @internal
 */
final readonly class UiSupport
{
    /** Translation domain. */
    public const DOMAIN = TranslationDomain::NAME;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private FormFactoryInterface $forms,
        private CrmKitGuard $guard,
        private TranslatorInterface $translator,
        private CsrfTokenManagerInterface $csrf,
        private ?string $layout = null,
        public int $pageSize = 25,
        private bool $pipeline = false,
        public UiOptions $options = new UiOptions(),
    ) {
    }

    /**
     * A translation label of the bundle.
     *
     * @param array<string, float|int|string|null> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * A translated text of the bundle.
     *
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, self::DOMAIN);
    }

    /**
     * A host label (0.7.0): a key of the host's own domain, or a plain text when `$domain` is null.
     */
    public function hostLabel(HostTypeLabel $label): string
    {
        return $label->domain === null ? $label->text : $this->translator->trans($label->text, [], $label->domain);
    }

    /**
     * Renders a template.
     *
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    public function render(string $template, array $context, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return new Response($this->twig->render($template, $context + ['crm_layout' => $this->layout, 'crm_filters_slot' => $this->options->filtersSlot]), $status, $headers);
    }

    /**
     * The layout of the pages (null: UiKit's admin layout).
     */
    public function layout(): ?string
    {
        return $this->layout;
    }

    /**
     * A route URL.
     *
     * @param array<string, mixed> $params
     */
    public function url(string $route, array $params = []): string
    {
        return $this->router->generate($route, $params);
    }

    /**
     * A 303 redirect to a route.
     *
     * @param array<string, mixed> $params
     */
    public function redirect(string $route, array $params = []): RedirectResponse
    {
        return new RedirectResponse($this->url($route, $params), Response::HTTP_SEE_OTHER);
    }

    /**
     * A 303 redirect to a record's page.
     */
    public function toRecord(SubjectType $type, string $id): RedirectResponse
    {
        return $this->redirect('nowo_crm_kit_show', ['type' => $type->segment(), 'id' => $id]);
    }

    /**
     * Adds a translated flash.
     *
     * @param array<string, int|string> $params
     */
    public function flash(Request $request, string $type, string $key, array $params = []): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->trans($key, $params));
        }
    }

    /**
     * Runs a page and maps the gate's refusals: 404, then 403.
     *
     * @param callable(): Response $page
     */
    public function guard(callable $page): Response
    {
        try {
            return $page();
        } catch (NotAvailableException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        } catch (AccessDeniedException $e) {
            throw new AccessDeniedHttpException('Forbidden.', $e);
        }
    }

    /**
     * Runs a POST action that redirects; domain conflicts and input errors become a flash and the 303 redirect to
     * `$back` (the page the browser follows to shows the flash). Lock conflicts are not caught: they keep their 409.
     *
     * @param callable(): Response $action
     */
    public function act(Request $request, callable $action, RedirectResponse $back): Response
    {
        return $this->guard(function () use ($request, $action, $back): Response {
            try {
                return $action();
            } catch (ConflictException $e) {
                $this->flash($request, 'error', 'flash.conflict.' . $e->errorCode);

                return $back;
            } catch (InvalidInputException $e) {
                foreach ($e->errors as $key) {
                    $this->flash($request, 'error', $key, $e->params);
                }

                return $back;
            }
        });
    }

    /**
     * Copies domain input errors onto a form (field `custom.<key>` → child `custom_<key>`, `$fields` maps a domain
     * field to its form child, e.g. `company_id` → `company` (0.8.1), unknown fields → form).
     *
     * @param FormInterface<mixed> $form
     * @param array<string, string> $fields domain field => form child
     */
    public function errors(FormInterface $form, InvalidInputException $e, array $fields = []): void
    {
        foreach ($e->errors as $field => $key) {
            $name    = $fields[$field] ?? (str_starts_with($field, 'custom.') ? 'custom_' . substr($field, 7) : $field);
            $message = $this->trans($key, $e->params);
            if ($form->has($name)) {
                $form->get($name)->addError(new FormError($message));
            } else {
                $form->addError(new FormError($message));
            }
        }
    }

    /**
     * A named form.
     *
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $options
     *
     * @return FormInterface<mixed>
     */
    public function form(string $type, ?array $data, array $options, string $name): FormInterface
    {
        return $this->forms->createNamed($name, $type, $data, $options);
    }

    /**
     * The view of a form whose element ids start with `$idPrefix` instead of the form name (0.6.1): two renderings of
     * the same form on one page (a dialog and its no-JS fallback, one dialog per subject) keep unique ids, while the
     * field names (`crm_tag[name]`…) and so the POST contract stay the same.
     *
     * @param FormInterface<mixed> $form
     */
    public function view(FormInterface $form, string $idPrefix): FormView
    {
        $view = $form->createView();
        self::prefixIds($view, $form->getName(), $idPrefix);

        return $view;
    }

    /**
     * Rewrites the ids of a view tree that start with `$from`.
     */
    private static function prefixIds(FormView $view, string $from, string $to): void
    {
        $id = $view->vars['id'] ?? null;
        if (is_string($id) && str_starts_with($id, $from)) {
            $view->vars['id'] = $to . substr($id, strlen($from));
        }
        foreach ($view->children as $child) {
            self::prefixIds($child, $from, $to);
        }
    }

    /**
     * The pager of a list (0.8.0): UiKit's {@see Pagination} with the per-page values the list accepts, the page size
     * as the default and the request query kept across pages (filters, sort). The `_pagination` partial prints the
     * "Showing x–y of z" line under the table even on a single page, and the page links from two pages on.
     */
    public function pagination(int $total, int $page, int $perPage, string $route, Request $request): Pagination
    {
        $query = $request->query->all();
        unset($query['page']);

        // 0.10.2: with ui.per_page_position: pagination the compact pager carries the "Rows per page" select.
        return Pagination::fromTotal($total, $page, $perPage, $this->options->allowedPerPage($this->pageSize), $this->pageSize, $route, $query, variant: $this->options->perPageInPagination() ? Pagination::VARIANT_COMPACT : Pagination::VARIANT_FULL);
    }

    /**
     * The `per_page` choices of a list's filter form (0.10.2): the configured ones when the select sits in the filter
     * actions, none when it sits in the pagination bar (or there are none).
     *
     * @return list<int>
     */
    public function filterPerPage(): array
    {
        return $this->options->perPageInFilters() ? $this->options->listPerPage : [];
    }

    /**
     * The UiKit options of a list's filter fields (0.10.2, Beacon filter standard): the search one grid column wide
     * (`ui.search_width`), the list's `ui.filters_more` fields under "More filters", the "Rows per page" select compact
     * before Filter / Clear, and any field options of the page.
     *
     * @param string|null $list `companies` | `contacts` | `deals` (null: no fold, e.g. the admin lists)
     * @param array<string, bool|float|int|string|null> $searchAttributes widget attributes of the search (aria-label…)
     * @param bool $keyset a keyset list (Deals: no numbered pager), whose "Rows per page" stays in the filters whatever
     *                     `ui.per_page_position` says
     *
     * @return list<FilterField>
     */
    public function filterFields(?string $list, Label|string|null $searchPlaceholder, array $searchAttributes = [], string $search = 'q', bool $keyset = false): array
    {
        $fields = [new FilterField($search, $searchPlaceholder, $this->options->searchWidth, attributes: $searchAttributes)];
        foreach ($list === null ? [] : $this->options->filtersMoreOf($list) as $name) {
            $fields[] = new FilterField($name, more: true);
        }

        return [...$fields, ...$this->perPageField($keyset)];
    }

    /**
     * Owner filter choices (0.10.2): "Mine" and "No owner" first, then the people (name ⇒ id).
     *
     * @param array<string, string> $owners
     *
     * @return array<string, string>
     */
    public function ownerChoices(array $owners): array
    {
        return [$this->trans('list.filter.owner_me') => ListFilters::OWNER_ME, $this->trans('list.filter.owner_none') => ListFilters::OWNER_NONE] + $owners;
    }

    /**
     * The view of a list's filter form with the rows per page the list used (0.8.0): a `per_page` outside the
     * configured choices shows the page size the list fell back to, not the browser's first option.
     *
     * @param FormInterface<mixed> $form
     */
    public function filterView(FormInterface $form, int $perPage): FormView
    {
        $view = $form->createView();
        if (isset($view->children['per_page'])) {
            $view->children['per_page']->vars['value'] = (string) $perPage;
            $view->children['per_page']->vars['data']  = $perPage;
        }

        return $view;
    }

    /**
     * The UiKit options of the "Rows per page" filter (0.8.0): none without `ui.list_per_page` or with
     * `ui.per_page_position: pagination`; 0.10.2: compact, right before Filter / Clear.
     *
     * @param bool $keyset a keyset list (Deals), whose select stays in the filters with `per_page_position: pagination`
     *
     * @return list<FilterField>
     */
    public function perPageField(bool $keyset = false): array
    {
        // 0.10.2: compact, the first item of the filter actions (UiKit FilterField pager), with the host's row class.
        return ($keyset ? $this->options->perPageSelect() : $this->options->perPageInFilters()) ? [new FilterField('per_page', rowClass: $this->options->perPageRowClass, rowAttributes: ['data-testid' => 'crm-per-page'], pager: true)] : [];
    }

    /**
     * The CSRF token of the action buttons (`crm_action[_token]`).
     */
    public function actionToken(): string
    {
        return $this->csrf->getToken('nowo_crm_kit_action')->getValue();
    }

    /**
     * Requires a tenant-wide permission (404 when the CRM is not available, 403 without it).
     */
    public function require(CrmPermission $permission): void
    {
        $this->guard->requireGranted($permission);
    }

    /**
     * Requires a permission on one record (403 without it).
     */
    public function requireOn(CrmPermission $permission, RecordView $view): void
    {
        $this->guard->requireGranted($permission, SubjectRef::of($view->type, $view->id, $view->ownerId));
    }

    /**
     * Requires a permission on one subject, a deal included (0.3.0; 403 without it).
     */
    public function requireSubject(CrmPermission $permission, SubjectRef $subject): void
    {
        $this->guard->requireGranted($permission, $subject);
    }

    /**
     * Whether the actor holds a permission (menus, buttons).
     */
    public function can(CrmPermission $permission): bool
    {
        return $this->guard->isGranted($permission);
    }

    /**
     * The area tabs of a list, admin or form page: the area navigation, or null with `ui.list_tabs: false` (0.6.1).
     */
    public function tabs(string $current): ?AreaNav
    {
        return $this->options->listTabs ? $this->areaNav($current) : null;
    }

    /**
     * The CRM's area navigation, with the entries the actor may open (the access checks decide the entries; the icon
     * of each one, `ui.tab_icons` over the built-in set, is decoration only).
     */
    public function areaNav(string $current): AreaNav
    {
        $items = [
            new AreaNavItem(self::label('nav.companies'), $this->url('nowo_crm_kit_list', ['type' => 'companies']), $current === 'companies', icon: $this->options->tabIcon('companies')),
            new AreaNavItem(self::label('nav.contacts'), $this->url('nowo_crm_kit_list', ['type' => 'contacts']), $current === 'contacts', icon: $this->options->tabIcon('contacts')),
        ];
        if ($this->pipeline) {
            $items[] = new AreaNavItem(self::label('nav.deals'), $this->url('nowo_crm_kit_deals'), $current === 'deals', icon: $this->options->tabIcon('deals'));
        }
        if ($this->can(CrmPermission::Manage)) {
            $items[] = new AreaNavItem(self::label('nav.tags'), $this->url('nowo_crm_kit_tags'), $current === 'tags', icon: $this->options->tabIcon('tags'));
            $items[] = new AreaNavItem(self::label('nav.fields'), $this->url('nowo_crm_kit_fields'), $current === 'fields', icon: $this->options->tabIcon('fields'));
            $items[] = new AreaNavItem(self::label('nav.settings'), $this->url('nowo_crm_kit_settings'), $current === 'settings', icon: $this->options->tabIcon('settings'));
            if ($this->pipeline) {
                $items[] = new AreaNavItem(self::label('nav.funnels'), $this->url('nowo_crm_kit_funnels'), $current === 'funnels', icon: $this->options->tabIcon('funnels'));
            }
        }

        return new AreaNav($items, self::label('nav.label'));
    }
}
