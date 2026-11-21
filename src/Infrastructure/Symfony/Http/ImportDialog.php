<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wire protocol of the import modal (0.4.0, `js/nowo-crm-import.js`): the modal asks the import routes with
 * {@see self::REQUEST_HEADER} `import` and gets the step fragment (marked with {@see self::FRAGMENT_HEADER}) instead of
 * the page; a step that would redirect answers 204 with {@see self::LOCATION_HEADER}, the next step's address; nobody
 * signed in answers 401 `session_expired`, so the modal shows its reload notice instead of a login page. The same
 * routes, access checks and CSRF tokens as the pages. Stateless.
 */
final class ImportDialog
{
    /** Request header of the modal's calls, value {@see self::REQUEST_VALUE}. */
    public const REQUEST_HEADER = 'X-Nowo-Crm-Dialog';

    /** Value of {@see self::REQUEST_HEADER}. */
    public const REQUEST_VALUE = 'import';

    /** Response header of a step fragment, value {@see self::FRAGMENT_VALUE}. */
    public const FRAGMENT_HEADER = 'X-Nowo-Crm-Fragment';

    /** Value of {@see self::FRAGMENT_HEADER}. */
    public const FRAGMENT_VALUE = 'import-step';

    /** Response header of a 204: the address of the next step (same origin, a path of the import routes). */
    public const LOCATION_HEADER = 'X-Nowo-Crm-Dialog-Location';

    /**
     * Whether the request comes from the import modal.
     */
    public static function requested(Request $request): bool
    {
        return $request->headers->get(self::REQUEST_HEADER) === self::REQUEST_VALUE;
    }

    /**
     * Headers of a step fragment.
     *
     * @return array<string, string>
     */
    public static function fragmentHeaders(): array
    {
        return [self::FRAGMENT_HEADER => self::FRAGMENT_VALUE] + self::privateHeaders();
    }

    /**
     * Headers of every answer of the import routes, page or fragment: never cached, and the answer depends on
     * {@see self::REQUEST_HEADER}.
     *
     * @return array<string, string>
     */
    public static function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Vary' => self::REQUEST_HEADER];
    }

    /**
     * 204 with the next step's address.
     */
    public static function next(string $url): Response
    {
        return new Response(null, Response::HTTP_NO_CONTENT, [self::LOCATION_HEADER => $url] + self::privateHeaders());
    }

    /**
     * 401 `session_expired`: nobody is signed in any more (the modal shows its reload notice).
     */
    public static function expired(): JsonResponse
    {
        return new JsonResponse(['error' => 'session_expired', 'reload' => true], Response::HTTP_UNAUTHORIZED, self::privateHeaders());
    }
}
