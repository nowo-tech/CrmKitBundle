<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;

use function in_array;

/**
 * Turns the CRM's domain exceptions into API refusals (0.3.0, design §11): not available ⇒ `ACCESS_001`, refused ⇒
 * `ACCESS_002`, invalid input ⇒ `INPUT_004` with the fields (a missing lost reason ⇒ `CRM_007`), domain conflicts ⇒
 * `CRM_0xx`, task-port refusals ⇒ the port's code, `CRM_009` or `CRM_010`. transaction-doctrine's lock conflicts and
 * every other failure pass through unchanged. Stateless.
 *
 * @internal
 */
final class CrmApiErrors
{
    private function __construct()
    {
    }

    /**
     * Runs `$work`, converting its domain exceptions.
     *
     * @template T
     *
     * @param callable(): T $work
     * @param (callable(ConflictException): array<string, int|string|null>)|null $extra the extras of a domain conflict
     *                                                                                  (current version, visible ids)
     *
     * @return T
     */
    public static function run(callable $work, ?callable $extra = null): mixed
    {
        try {
            return $work();
        } catch (NotAvailableException $e) {
            throw CrmApiRefusal::notFound($e);
        } catch (AccessDeniedException $e) {
            throw CrmApiRefusal::denied($e);
        } catch (InvalidInputException $e) {
            if (in_array($e->errors['lost_reason'] ?? null, ['error.lost_reason_required'], true)) {
                throw CrmApiRefusal::of(CrmApiCode::LOST_REASON, [], $e);
            }

            throw CrmApiRefusal::refused($e->errors, $e);
        } catch (ConflictException $e) {
            $code = match ($e->errorCode) {
                ConflictException::STALE         => CrmApiCode::STALE,
                ConflictException::MERGED        => CrmApiCode::MERGED,
                ConflictException::TRASHED       => CrmApiCode::TRASHED,
                ConflictException::LIMIT         => CrmApiCode::LIMIT,
                ConflictException::STAGE_INVALID => CrmApiCode::STAGE,
                default                          => CrmApiCode::WRITE_CONFLICT,
            };
            $values = $extra === null ? [] : $extra($e);
            $kept   = [];
            foreach (CrmApiCode::EXTRA[$code] ?? [] as $name) {
                if (($values[$name] ?? null) !== null) {
                    $kept[$name] = $values[$name];
                }
            }

            throw CrmApiRefusal::of($code, $kept, $e);
        } catch (CrmTaskRefusedException $e) {
            throw $e->transient ? CrmApiRefusal::of(CrmApiCode::TASK_UNAVAILABLE, [], $e) : CrmApiRefusal::foreign($e->errorCode, $e);
        }
    }
}
