<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * A domain conflict (contract section 11.3): 409 with its own body code and no `Retry-After` (retrying the same request
 * cannot succeed): `crm_record_stale`, `crm_record_merged`, `crm_record_trashed`, `crm_merge_stale`,
 * `crm_import_running`, `crm_limit_reached`, `crm_settings_stale`.
 */
final class ConflictException extends RuntimeException implements CrmException
{
    public const STALE = 'crm_record_stale';

    public const MERGED = 'crm_record_merged';

    public const TRASHED = 'crm_record_trashed';

    public const MERGE_STALE = 'crm_merge_stale';

    public const IMPORT_RUNNING = 'crm_import_running';

    public const LIMIT = 'crm_limit_reached';

    public const SETTINGS_STALE = 'crm_settings_stale';

    /**
     * @param string $errorCode one of the constants
     * @param string|null $recordId the record the conflict is about (merged: the survivor)
     */
    public function __construct(public readonly string $errorCode, public readonly ?string $recordId = null, ?Throwable $previous = null)
    {
        parent::__construct('Conflict: ' . $errorCode . '.', 0, $previous);
    }
}
