<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * A domain conflict (contract section 11.3): 409 with its own body code and no `Retry-After` (retrying the same request
 * cannot succeed): `crm_record_stale`, `crm_record_merged`, `crm_record_trashed`, `crm_merge_stale`,
 * `crm_import_running`, `crm_limit_reached`, `crm_settings_stale`, `crm_write_conflict` (a unique key refused the
 * write twice: another writer stored the same row at the same moment; a retry from the client is safe),
 * `crm_stage_invalid` (0.3.0), `crm_hubspot_rate_limited` (0.10.0).
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

    public const WRITE_CONFLICT = 'crm_write_conflict';

    /** 0.3.0: the stage is not one of the deal's funnel, the funnel or the stage is archived, or a create targets a closed stage. */
    public const STAGE_INVALID = 'crm_stage_invalid';

    /** A next action's dedupe key already names another subject (0.3.0). */
    public const DEDUPE_REUSED = 'crm_dedupe_reused';

    /** 0.10.0: too many HubSpot token checks for the tenant (`import.hubspot.connect_rate_limit`). */
    public const HUBSPOT_RATE_LIMITED = 'crm_hubspot_rate_limited';

    /** 0.13.0: this *Write e-mail* form was sent already (a second submission of the same form). */
    public const MAIL_SENT = 'crm_mail_already_sent';

    /**
     * @param string $errorCode one of the constants
     * @param string|null $recordId the record the conflict is about (merged: the survivor)
     */
    public function __construct(public readonly string $errorCode, public readonly ?string $recordId = null, ?Throwable $previous = null)
    {
        parent::__construct('Conflict: ' . $errorCode . '.', 0, $previous);
    }
}
