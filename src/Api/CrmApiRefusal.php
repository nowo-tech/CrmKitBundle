<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

use LogicException;
use RuntimeException;
use Throwable;

use function array_diff;
use function array_keys;
use function in_array;
use function is_int;
use function is_string;

/**
 * The one refusal of the API (0.3.0, design §11): an HTTP status, a code of {@see CrmApiCode}, its static message
 * and a few extras (ints and ids only, from the code's fixed list). The JSON API answers
 * `{"error": message, "code": code, ...extra}` with `X-Error-Code`; an MCP host passes the code through.
 */
final class CrmApiRefusal extends RuntimeException
{
    /**
     * @param array<string, array<string, string>|int|string|null> $extra
     */
    private function __construct(public readonly int $status, public readonly string $errorCode, public readonly array $extra = [], ?Throwable $previous = null)
    {
        parent::__construct(CrmApiCode::MESSAGES[$errorCode] ?? CrmApiCode::MESSAGES[CrmApiCode::SERVER], 0, $previous);
    }

    /**
     * A refusal of a listed code.
     *
     * @param array<string, array<string, string>|int|string|null> $extra
     *
     * @throws LogicException for an unknown code or an extra the code does not list
     */
    public static function of(string $code, array $extra = [], ?Throwable $previous = null): self
    {
        $status = CrmApiCode::STATUS[$code] ?? null;
        if (!is_int($status) || array_diff(array_keys($extra), CrmApiCode::EXTRA[$code] ?? []) !== []) {
            throw new LogicException('Unknown API refusal.');
        }

        return new self($status, $code, $extra, $previous);
    }

    /**
     * A refusal of a code another port chose (a task port): kept when it has the code shape and is not one of the
     * conflict codes, else `CRM_010`.
     */
    public static function foreign(?string $code, ?Throwable $previous = null): self
    {
        if (is_string($code) && isset(CrmApiCode::STATUS[$code]) && !in_array($code, [CrmApiCode::CONFLICT_UNCOMMITTED, CrmApiCode::CONFLICT_COMMITTED, CrmApiCode::SERVER], true)) {
            return self::of($code, [], $previous);
        }

        return self::of(CrmApiCode::TASK_REFUSED, [], $previous);
    }

    /** 404 `ACCESS_001`. */
    public static function notFound(?Throwable $previous = null): self
    {
        return self::of(CrmApiCode::NOT_FOUND, [], $previous);
    }

    /** 403 `TOKEN_001`. */
    public static function token(): self
    {
        return self::of(CrmApiCode::TOKEN);
    }

    /** 403 `ACCESS_002`. */
    public static function denied(?Throwable $previous = null): self
    {
        return self::of(CrmApiCode::DENIED, [], $previous);
    }

    /** 400 `INPUT_002` on a field. */
    public static function malformed(string $field): self
    {
        return self::of(CrmApiCode::INPUT_MALFORMED, ['field' => $field]);
    }

    /** 400 `INPUT_001` on a field. */
    public static function missing(string $field): self
    {
        return self::of(CrmApiCode::INPUT_MISSING, ['field' => $field]);
    }

    /**
     * 422 `INPUT_004` with the fields and their translation keys.
     *
     * @param array<string, string> $fields
     */
    public static function refused(array $fields, ?Throwable $previous = null): self
    {
        return self::of(CrmApiCode::INPUT_REFUSED, ['fields' => $fields], $previous);
    }
}
