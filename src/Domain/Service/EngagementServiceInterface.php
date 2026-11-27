<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * Logged activities (0.11.0, `activities.enabled`): calls, meetings and e-mails on a company or a contact. `edit` on
 * the record; changing or deleting someone else's activity needs `manage` too. A contact's activity is associated with
 * its companies and open deals when it is logged (a company's with its open deals).
 */
interface EngagementServiceInterface
{
    /**
     * Logs an activity on a record; its id.
     */
    public function log(SubjectType $type, string $subjectId, EngagementInput $input): string;

    /**
     * Changes an activity shown at `$expectedVersion` (its kind cannot change).
     */
    public function edit(string $id, EngagementInput $input, int $expectedVersion): void;

    /**
     * Deletes an activity.
     */
    public function delete(string $id): void;
}
