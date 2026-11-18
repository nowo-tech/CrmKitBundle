<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\MergePreview;

/**
 * Merging duplicates (contract section 15.3), `edit` on both records.
 */
interface MergeServiceInterface
{
    /**
     * What merging `$otherId` into `$survivorId` would do.
     */
    public function preview(SubjectType $type, string $survivorId, string $otherId): MergePreview;

    /**
     * Merges `$otherId` into `$survivorId`; both versions must still be the ones shown.
     *
     * @param list<string> $takeFromOther fields (core names or `custom.<key>`) whose value comes from the other record
     */
    public function merge(SubjectType $type, string $survivorId, string $otherId, array $takeFromOther, int $survivorVersion, int $otherVersion): void;
}
