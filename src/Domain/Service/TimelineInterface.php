<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * Host entries on a record's timeline (contract section 6.6), `edit`.
 */
interface TimelineInterface
{
    /**
     * Records a host entry: a translation key of the host's `messages` domain, ≤ 20 scalar parameters (no personal
     * data) and an optional local URL.
     *
     * @param array<string, bool|int|string|null> $params
     */
    public function record(SubjectType $type, string $subjectId, string $labelKey, array $params = [], ?string $url = null): void;
}
