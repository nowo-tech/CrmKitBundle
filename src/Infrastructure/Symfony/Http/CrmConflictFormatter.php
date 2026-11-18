<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Http;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * transaction-doctrine conflict formatter of the CRM's routes (contract section 15): a JSON request gets
 * `{"error": "concurrency_conflict", "code": "CONFLICT_001"}` (or the `_partial` / `CONFLICT_002` pair); the responder
 * adds the status, `Retry-After` (CONFLICT_001 only), `X-Conflict-Code` and `Cache-Control: no-store`. HTML falls
 * through to the responder's branded page. Stateless.
 */
final readonly class CrmConflictFormatter implements ConflictResponseFormatterInterface
{
    /**
     * The JSON answer on the CRM's routes; null elsewhere and for HTML.
     */
    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        if ($request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) !== true || !self::wantsJson($request)) {
            return null;
        }

        return new JsonResponse(['error' => $context->errorCode, 'code' => $context->code->value], $context->status);
    }

    /**
     * Whether the request asks for JSON.
     */
    public static function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json';
    }
}
