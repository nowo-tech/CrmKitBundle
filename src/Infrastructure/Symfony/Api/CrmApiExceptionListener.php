<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

use function is_a;

/**
 * Answers every failure of a `/crm/v1` route in the API's error shape (0.3.0, design §11): `{"error": <static
 * message>, "code": <CODE>, ...extra}` with `X-Error-Code`. A lock conflict (REQ-RUNTIME-003 rule 6) is
 * `CONFLICT_001` with `Retry-After` when nothing committed, `CONFLICT_002` without it once something did; both carry
 * `X-Conflict-Code` — the only codes that ever do. Anything else is 500 `SERVER_001` with no detail (logged by class
 * name). Stateless.
 */
final readonly class CrmApiExceptionListener
{
    /**
     * Wires the policy and the logger.
     */
    public function __construct(private ConflictResponsePolicy $policy = new ConflictResponsePolicy(), private ?LoggerInterface $logger = null)
    {
    }

    /**
     * `kernel.exception`.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get(CrmApiRouteLoader::ROUTE_ATTRIBUTE) !== true) {
            return;
        }
        $failure = $event->getThrowable();
        // The firewall's own answers (401 / 403, a login entry point) and HTTP exceptions keep their responses
        // (audit VS-4): they are not failures of the API.
        if ($failure instanceof HttpExceptionInterface || is_a($failure, 'Symfony\\Component\\Security\\Core\\Exception\\AuthenticationException') || is_a($failure, 'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException')) {
            return;
        }
        $headers  = ['Cache-Control' => 'no-store, private'];
        $refusal  = null;
        $conflict = null;
        for ($e = $failure; $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof CrmApiRefusal) {
                $refusal = $e;
                break;
            }
            if ($e instanceof LockConflictExceptionInterface) {
                $conflict = $e;
                break;
            }
        }
        if ($refusal instanceof CrmApiRefusal) {
            $response = self::json($refusal->errorCode, $refusal->status, $refusal->extra, $headers);
        } elseif ($conflict instanceof Throwable) {
            $code     = ConflictCode::forRequest($request, $failure);
            $api      = $code->retrySafe() ? CrmApiCode::CONFLICT_UNCOMMITTED : CrmApiCode::CONFLICT_COMMITTED;
            $response = self::json($api, 409, [], $this->policy->headersFor($code) + $headers);
        } else {
            $this->logger?->error('nowo_crm_kit API failure.', ['exception' => $failure::class]);
            $response = self::json(CrmApiCode::SERVER, 500, [], $headers);
        }
        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }

    /**
     * The error answer of a code.
     *
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public static function json(string $code, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => CrmApiCode::MESSAGES[$code] ?? CrmApiCode::MESSAGES[CrmApiCode::SERVER], 'code' => $code] + $extra, $status, $headers + ['X-Error-Code' => $code]);
    }
}
