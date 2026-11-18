<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Http;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Throwable;

/**
 * Registered only when NowoTransactionDoctrineBundle's conflict responder is absent: a lock conflict on the CRM's
 * routes still answers 409 with `CONFLICT_001` + `Retry-After` (or `CONFLICT_002` without it) and `X-Conflict-Code`.
 */
final readonly class ConflictFallbackListener
{
    /**
     * Wires the policy.
     */
    public function __construct(private ConflictResponsePolicy $policy = new ConflictResponsePolicy())
    {
    }

    /**
     * `kernel.exception`.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) !== true) {
            return;
        }
        $conflict = null;
        for ($e = $event->getThrowable(); $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof LockConflictExceptionInterface) {
                $conflict = $e;
                break;
            }
        }
        if (!$conflict instanceof Throwable) {
            return;
        }
        $code     = ConflictCode::forRequest($request, $event->getThrowable());
        $headers  = $this->policy->headersFor($code) + ['Cache-Control' => 'no-store, private'];
        $error    = $code->retrySafe() ? 'concurrency_conflict' : 'concurrency_conflict_partial';
        $response = CrmConflictFormatter::wantsJson($request)
            ? new JsonResponse(['error' => $error, 'code' => $code->value], $this->policy->status, $headers)
            : new Response('Someone changed this at the same moment. ' . ($code->retrySafe() ? 'Try again.' : 'Reload before trying again.'), $this->policy->status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }
}
