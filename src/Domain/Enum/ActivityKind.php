<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

use function in_array;

/**
 * Kinds of timeline entries (contract section 6.6). **(0.11.0, `activities.enabled`)** `Call`, `Meeting` and `Email` are
 * the logged activities (an entry on the record they were logged on, pointing at the activity by id) and
 * `ActivityEdited` / `ActivityDeleted` their changes; `FormSubmitted` and `Sms` are reserved for later blocks (never
 * written by this release).
 */
enum ActivityKind: string
{
    case Created          = 'created';
    case Updated          = 'updated';
    case OwnerChanged     = 'owner_changed';
    case Trashed          = 'trashed';
    case Restored         = 'restored';
    case Merged           = 'merged';
    case NoteAdded        = 'note_added';
    case NoteEdited       = 'note_edited';
    case NoteDeleted      = 'note_deleted';
    case Tagged           = 'tagged';
    case Untagged         = 'untagged';
    case Linked           = 'linked';
    case Unlinked         = 'unlinked';
    case Affiliated       = 'affiliated';
    case Unaffiliated     = 'unaffiliated';
    case RolesChanged     = 'roles_changed';
    case PrimaryContact   = 'primary_contact';
    case ConsentGiven     = 'consent_given';
    case ConsentWithdrawn = 'consent_withdrawn';
    case Imported         = 'imported';
    case Exported         = 'exported';
    case Host             = 'host';
    case StageMoved       = 'stage_moved';
    case NextActionAdded  = 'next_action_added';
    case NextActionClosed = 'next_action_closed';
    case LifecycleStage   = 'lifecycle_stage';
    case Call             = 'call';
    case Meeting          = 'meeting';
    case Email            = 'email';
    case ActivityEdited   = 'activity_edited';
    case ActivityDeleted  = 'activity_deleted';
    case FormSubmitted    = 'form_submitted';
    case Sms              = 'sms';

    /** The kinds a person logs as an activity (0.11.0). */
    public const LOGGABLE = [self::Call, self::Meeting, self::Email];

    /**
     * Whether the kind is a logged activity (`call`, `meeting`, `email`).
     */
    public function isLoggable(): bool
    {
        return in_array($this, [self::Call, self::Meeting, self::Email], true);
    }
}
