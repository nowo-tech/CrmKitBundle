<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

/**
 * Every refusal code of the API (0.3.0, design §11): always `^[A-Z][A-Z0-9]*_[0-9]{3}$`, so an MCP host passes them
 * through unchanged. {@see self::STATUS} and {@see self::MESSAGES} give the HTTP status and the static English message
 * of each; a message never carries an id, a name or input.
 */
final class CrmApiCode
{
    public const PATTERN = '/\A[A-Z][A-Z0-9]*_[0-9]{3}\z/';

    public const INPUT_MISSING = 'INPUT_001';

    public const INPUT_MALFORMED = 'INPUT_002';

    public const INPUT_CURSOR = 'INPUT_003';

    public const INPUT_REFUSED = 'INPUT_004';

    public const IDEMP_MISMATCH = 'IDEMP_002';

    public const IDEMP_INVALID = 'IDEMP_003';

    public const IDEMP_STORE = 'IDEMP_004';

    public const IDEMP_MISSING = 'IDEMP_005';

    public const VERSION_MISSING = 'VERSION_001';

    public const NOT_FOUND = 'ACCESS_001';

    public const TOKEN = 'TOKEN_001';

    public const DENIED = 'ACCESS_002';

    public const STALE = 'CRM_001';

    public const MERGED = 'CRM_002';

    public const TRASHED = 'CRM_003';

    public const LIMIT = 'CRM_004';

    public const WRITE_CONFLICT = 'CRM_005';

    public const HIDDEN_DUPLICATE = 'CRM_006';

    public const LOST_REASON = 'CRM_007';

    public const STAGE = 'CRM_008';

    public const TASK_UNAVAILABLE = 'CRM_009';

    public const TASK_REFUSED = 'CRM_010';

    public const CONFLICT_UNCOMMITTED = 'CONFLICT_001';

    public const CONFLICT_COMMITTED = 'CONFLICT_002';

    public const SERVER = 'SERVER_001';

    /** HTTP status of each code. */
    public const STATUS = [
        self::INPUT_MISSING        => 400,
        self::INPUT_MALFORMED      => 400,
        self::INPUT_CURSOR         => 400,
        self::INPUT_REFUSED        => 422,
        self::IDEMP_MISMATCH       => 409,
        self::IDEMP_INVALID        => 400,
        self::IDEMP_STORE          => 503,
        self::IDEMP_MISSING        => 428,
        self::VERSION_MISSING      => 428,
        self::NOT_FOUND            => 404,
        self::TOKEN                => 403,
        self::DENIED               => 403,
        self::STALE                => 409,
        self::MERGED               => 409,
        self::TRASHED              => 409,
        self::LIMIT                => 409,
        self::WRITE_CONFLICT       => 409,
        self::HIDDEN_DUPLICATE     => 409,
        self::LOST_REASON          => 422,
        self::STAGE                => 409,
        self::TASK_UNAVAILABLE     => 503,
        self::TASK_REFUSED         => 502,
        self::CONFLICT_UNCOMMITTED => 409,
        self::CONFLICT_COMMITTED   => 409,
        self::SERVER               => 500,
    ];

    /** Static English message of each code. */
    public const MESSAGES = [
        self::INPUT_MISSING        => 'A required field is missing.',
        self::INPUT_MALFORMED      => 'A field is malformed or unknown.',
        self::INPUT_CURSOR         => 'The cursor is not valid for this list. Start the list again.',
        self::INPUT_REFUSED        => 'The input was refused.',
        self::IDEMP_MISMATCH       => 'The idempotency key was used with another input.',
        self::IDEMP_INVALID        => 'The idempotency key is invalid.',
        self::IDEMP_STORE          => 'The idempotency store is unavailable.',
        self::IDEMP_MISSING        => 'An Idempotency-Key is required for this write.',
        self::VERSION_MISSING      => 'The base version (If-Match) is required for this write.',
        self::NOT_FOUND            => 'Not found.',
        self::TOKEN                => 'The token does not grant this operation.',
        self::DENIED               => 'You may not do this.',
        self::STALE                => 'The record changed since it was read.',
        self::MERGED               => 'The record was merged into another one.',
        self::TRASHED              => 'A matching record is in the trash.',
        self::LIMIT                => 'The plan limit is reached.',
        self::WRITE_CONFLICT       => 'Another write stored the same row at the same moment. Retry.',
        self::HIDDEN_DUPLICATE     => 'A matching record already exists.',
        self::LOST_REASON          => 'A lost reason is required for a lost stage.',
        self::STAGE                => 'The stage cannot be used here.',
        self::TASK_UNAVAILABLE     => 'The task service is unavailable. Nothing was written.',
        self::TASK_REFUSED         => 'The task service refused the task.',
        self::CONFLICT_UNCOMMITTED => 'Another request holds the record. Retry.',
        self::CONFLICT_COMMITTED   => 'A conflict happened after part of the request was saved.',
        self::SERVER               => 'Internal error.',
    ];

    /** Extra keys each code may carry (ints and ids only). */
    public const EXTRA = [
        self::INPUT_MISSING   => ['field'],
        self::INPUT_MALFORMED => ['field'],
        self::INPUT_REFUSED   => ['fields'],
        self::STALE           => ['current_version'],
        self::MERGED          => ['merged_into_id'],
        self::TRASHED         => ['record_id'],
    ];

}
