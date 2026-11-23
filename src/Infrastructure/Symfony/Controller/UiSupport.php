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
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Label;
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
    public const DOMAIN = NowoCrmKitBundle::TRANSLATION_DOMAIN;

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
     * Copies domain input errors onto a form (field `custom.<key>` → child `custom_<key>`, unknown fields → form).
     *
     * @param FormInterface<mixed> $form
     */
    public function errors(FormInterface $form, InvalidInputException $e): void
    {
        foreach ($e->errors as $field => $key) {
            $name    = str_starts_with($field, 'custom.') ? 'custom_' . substr($field, 7) : $field;
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
