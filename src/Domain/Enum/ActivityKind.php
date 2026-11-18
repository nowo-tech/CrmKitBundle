<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * Kinds of timeline entries (contract section 6.6).
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
    case ConsentGiven     = 'consent_given';
    case ConsentWithdrawn = 'consent_withdrawn';
    case Imported         = 'imported';
    case Exported         = 'exported';
    case Host             = 'host';
}
