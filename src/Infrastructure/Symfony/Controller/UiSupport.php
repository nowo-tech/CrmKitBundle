<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
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

use function str_starts_with;
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
        private CrmAccess $access,
        private TranslatorInterface $translator,
        private CsrfTokenManagerInterface $csrf,
        private ?string $layout = null,
        public int $pageSize = 25,
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
        return new Response($this->twig->render($template, $context + ['crm_layout' => $this->layout]), $status, $headers);
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
     * Runs a POST action that redirects; domain conflicts and input errors become a flash and a redirect to `$back`
     * (a conflict keeps its 409 status: the page the browser follows to shows the flash).
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
        $this->access->context($permission);
    }

    /**
     * Requires a permission on one record (403 without it).
     */
    public function requireOn(CrmPermission $permission, RecordView $view): void
    {
        $this->access->require($this->access->base(), $permission, SubjectRef::of($view->type, $view->id, $view->ownerId));
    }

    /**
     * Whether the actor holds a permission (menus, buttons).
     */
    public function can(CrmPermission $permission): bool
    {
        return $this->access->can($permission);
    }

    /**
     * The CRM's area navigation, with the entries the actor may open.
     */
    public function tabs(string $current): AreaNav
    {
        $items = [
            new AreaNavItem(self::label('nav.companies'), $this->url('nowo_crm_kit_list', ['type' => 'companies']), $current === 'companies'),
            new AreaNavItem(self::label('nav.contacts'), $this->url('nowo_crm_kit_list', ['type' => 'contacts']), $current === 'contacts'),
        ];
        if ($this->can(CrmPermission::Edit)) {
            $items[] = new AreaNavItem(self::label('nav.import'), $this->url('nowo_crm_kit_import'), $current === 'import');
        }
        if ($this->can(CrmPermission::Manage)) {
            $items[] = new AreaNavItem(self::label('nav.tags'), $this->url('nowo_crm_kit_tags'), $current === 'tags');
            $items[] = new AreaNavItem(self::label('nav.fields'), $this->url('nowo_crm_kit_fields'), $current === 'fields');
            $items[] = new AreaNavItem(self::label('nav.settings'), $this->url('nowo_crm_kit_settings'), $current === 'settings');
        }

        return new AreaNav($items, self::label('nav.label'));
    }
}
