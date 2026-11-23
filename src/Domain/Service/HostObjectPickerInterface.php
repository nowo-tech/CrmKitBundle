<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectSearchInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectMatch;

/**
 * The link picker of a record (0.7.0): the host objects the actor may link it to, found through the resolvers that
 * implement {@see CrmHostObjectSearchInterface}.
 */
interface HostObjectPickerInterface
{
    /** Most answers per host type. */
    public const MAX_LIMIT = 20;

    /** Longest search text in characters (longer text finds nothing). */
    public const MAX_TEXT = 100;

    /**
     * The host types that have a picker.
     *
     * @return list<string>
     */
    public function searchableTypes(): array;

    /**
     * Host objects matching the text, per searchable type, at most `$limit` (clamped to 1–{@see self::MAX_LIMIT}) of
     * each. Requires `edit` on the record, like the link itself (404 / 403 otherwise); in the actor's tenant only;
     * every answer is confirmed by the resolver's `resolve()` with its exact id.
     *
     * @return list<HostObjectMatch>
     */
    public function search(SubjectType $type, string $subjectId, string $text, int $limit = self::MAX_LIMIT): array;
}
