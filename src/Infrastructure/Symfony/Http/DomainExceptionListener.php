<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Http;

use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Maps the CRM's domain exceptions that reach the kernel on its routes (contract section 11.3): not available ⇒ 404,
 * access denied ⇒ 403; a domain conflict ⇒ 409 with its body code and no `Retry-After` / `X-Conflict-Code`; invalid
 * input ⇒ 422 (JSON) or 400. Pages handle the expected cases themselves; this is the safety net. Stateless.
 */
final readonly class DomainExceptionListener
{
    /**
     * `kernel.exception` (after the conflict responder).
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) !== true) {
            return;
        }
        $failure = $event->getThrowable();
        if ($failure instanceof NotAvailableException) {
            $event->setThrowable(new NotFoundHttpException('Not found.', $failure));

            return;
        }
        if ($failure instanceof AccessDeniedException) {
            $event->setThrowable(new AccessDeniedHttpException('Forbidden.', $failure));

            return;
        }
        $json = CrmConflictFormatter::wantsJson($request);
        if ($failure instanceof ConflictException) {
            $event->setResponse($json
                ? new JsonResponse(['error' => $failure->errorCode], Response::HTTP_CONFLICT, ['Cache-Control' => 'no-store, private'])
                : new Response($failure->errorCode, Response::HTTP_CONFLICT, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private']));
            $event->allowCustomResponseCode();

            return;
        }
        if ($failure instanceof InvalidInputException || $failure instanceof ImportFileException) {
            $errors = $failure instanceof InvalidInputException ? $failure->errors : ['file' => $failure->getMessage()];
            $event->setResponse($json
                ? new JsonResponse(['error' => 'invalid_input', 'fields' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY)
                : new Response('Invalid input.', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']));
            $event->allowCustomResponseCode();
        }
    }
}
