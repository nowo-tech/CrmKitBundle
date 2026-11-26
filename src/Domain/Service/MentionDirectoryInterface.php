<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\MentionCandidate;

/**
 * The @mentions of notes (0.11.0, `activities.enabled` and `activities.mentions.enabled`): MilkdownEditor's `@`
 * suggestion list asks for the candidates, the people of the same tenant who may view the record.
 */
interface MentionDirectoryInterface
{
    /**
     * Up to 10 members who may view the record whose name or e-mail contains the query (`edit` on the record).
     *
     * @return list<MentionCandidate>
     */
    public function candidates(SubjectType $type, string $id, string $query): array;

    /**
     * The names of handles of the tenant's members (`view` on the record), for MilkdownEditor's chips; unknown handles
     * are left out.
     *
     * @param list<string> $handles
     *
     * @return array<string, string> handle => name
     */
    public function names(SubjectType $type, string $id, array $handles): array;
}
