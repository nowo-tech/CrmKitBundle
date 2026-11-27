<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Nowo\UiKitBundle\Twig\ThemeExtension;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The forms of the funnel pages on demand (0.9.0, `ui.pipeline_forms: dialog`; owner rule "every dialog loads on
 * demand"). Each form is its own page: a GET answers the full page (the no-JS fallback and a deep link) or, with
 * `X-Nowo-Dialog: 1`, the fragment of UiKit's remote form dialog (`_dialog_form.html.twig`); an invalid submission
 * answers 422 in place, a saved one 204 + `X-Nowo-Redirect` (the page: a 303 with the flash). The openers are real
 * links carrying UiKit's remote form opener attributes; each page prints one empty dialog shell (`DIALOG`). Every answer
 * of a two-mode URL varies on the dialog header. Stateless.
 *
 * @internal
 */
final readonly class PipelineDialogs
{
    /** Id of the one remote form dialog of a funnel page. */
    public const DIALOG = 'crm-pipeline-dialog';

    /** Id of the one remote form dialog of the activity feed (0.11.0). */
    public const ACTIVITY_DIALOG = 'crm-activity-dialog';

    /**
     * Wires the collaborators (the responder is null without UiKit's remote dialog service: inline forms then).
     * **(0.11.0)** `$always`: the dialogs whatever `ui.pipeline_forms` says (the activity forms, which have no inline
     * variant), with their own dialog id.
     */
    public function __construct(private UiSupport $ui, private ?RemoteDialogResponder $dialog = null, private bool $always = false, private string $dialogId = self::DIALOG)
    {
    }

    /**
     * Whether the pages use the dialogs (`ui.pipeline_forms: dialog`, or always for the activity forms, and UiKit's
     * responder).
     */
    public function enabled(): bool
    {
        return ($this->always || $this->ui->options->pipelineDialogs()) && $this->dialog instanceof RemoteDialogResponder;
    }

    /**
     * The shell variable of a page: the dialog id and its default title, or null with inline forms.
     *
     * @return array{id: string, title: Label}|null
     */
    public function shell(string $title): ?array
    {
        return $this->enabled() ? ['id' => $this->dialogId, 'title' => UiSupport::label($title)] : null;
    }

    /**
     * An opener: a link to the form's own page that UiKit's runtime loads into the page's dialog.
     *
     * @param array<string, string> $params
     * @param array<string, int|string> $labelParams
     */
    public function opener(string $kind, string $label, string $route, array $params, string $testid, ?string $variant = null, array $labelParams = []): Action
    {
        $href = $this->ui->url($route, $params);

        return new Action($kind, UiSupport::label($label, $labelParams), $href, variant: $variant, attributes: ThemeExtension::remoteFormOpener($this->dialogId, $this->ui->trans($label, $labelParams), false, $href) + ['aria-haspopup' => 'dialog', 'aria-controls' => $this->dialogId, 'data-testid' => $testid]);
    }

    /**
     * A form page or, for a dialog request, its fragment. The fragment's form posts to `$action` (the page's own URL; the
     * page posts to itself); Cancel goes back to `$cancel`.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, int|string> $titleParams
     */
    public function render(Request $request, FormInterface $form, string $action, string $title, string $submit, string $cancel, string $testid, int $status, array $titleParams = [], ?string $intro = null, string $variant = 'btn_primary'): Response
    {
        if ($this->isDialog($request)) {
            return $this->vary($this->ui->render('@NowoCrmKit/_dialog_form.html.twig', [
                'form'    => $form->createView(),
                'action'  => $action,
                'dialog'  => $this->dialogId,
                'testid'  => $testid . '-dialog-form',
                'submit'  => $submit,
                'intro'   => $intro === null ? null : $this->ui->trans($intro),
                'variant' => $variant,
            ], $status));
        }

        return $this->vary($this->ui->render('@NowoCrmKit/pipeline/form.html.twig', [
            'page' => new FormPage(
                new PageHeader(UiSupport::label($title, $titleParams), $intro === null ? null : UiSupport::label($intro), []),
                $form->createView(),
                UiSupport::label($submit),
                $cancel,
                UiSupport::label('form.cancel'),
                bundle: UiSupport::DOMAIN,
                layout: $this->ui->layout(),
                attributes: ['data-testid' => $testid],
            ),
        ], $status));
    }

    /**
     * The answer of a saved form: 204 + `X-Nowo-Redirect` in the dialog, else the 303 of the page (the flash is
     * already set; the page the browser lands on shows it).
     *
     * @param array<string, string> $params
     */
    public function done(Request $request, string $route, array $params = []): Response
    {
        return $this->isDialog($request) && $this->dialog instanceof RemoteDialogResponder
            ? $this->dialog->redirectToRoute($route, $params, Response::HTTP_SEE_OTHER, $request)
            : $this->vary($this->ui->redirect($route, $params));
    }

    /**
     * Saves a valid form: the answer of `$save` (the 204 / 303 of {@see self::done()}), else the status of the form
     * shown again with the reason on it — an input error on its field (or the form) 422, a domain conflict (a stale
     * version, a stage gone, a record in the trash) 422 in the dialog and 409 for the page. Lock conflicts are not
     * caught: they keep the 409 of the write unit (REQ-RUNTIME-003).
     *
     * @param FormInterface<mixed> $form
     * @param callable(): Response $save
     */
    public function attempt(Request $request, FormInterface $form, callable $save): Response|int
    {
        try {
            return $save();
        } catch (InvalidInputException $e) {
            $this->ui->errors($form, $e);

            return Response::HTTP_UNPROCESSABLE_ENTITY;
        } catch (ConflictException $e) {
            return $this->conflict($request, $form, $e);
        }
    }

    /**
     * A domain conflict of a submission (a stale version, a stage gone): the message on the form, and the status of
     * the answer: 422 in the dialog (re-rendered in place), 409 for the page (REQ-RUNTIME-003). Lock conflicts are not
     * caught here: they keep the 409 of the write unit.
     *
     * @param FormInterface<mixed> $form
     */
    public function conflict(Request $request, FormInterface $form, ConflictException $e): int
    {
        $form->addError(new FormError($this->ui->trans('flash.conflict.' . $e->errorCode)));

        return $this->isDialog($request) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;
    }

    /**
     * Whether the request is a dialog request of an enabled dialog.
     */
    public function isDialog(Request $request): bool
    {
        return $this->enabled() && $this->dialog instanceof RemoteDialogResponder && $this->dialog->isDialog($request);
    }

    /**
     * Adds `Vary: X-Nowo-Dialog` to an answer of a URL that serves the page and the fragment.
     */
    private function vary(Response $response): Response
    {
        return $this->dialog instanceof RemoteDialogResponder ? $this->dialog->fragment($response) : $response;
    }
}
