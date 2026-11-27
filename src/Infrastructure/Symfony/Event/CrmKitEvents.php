<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Event;

use Nowo\CrmKitBundle\Domain\Event\ActorAnonymised;
use Nowo\CrmKitBundle\Domain\Event\ConsentRecorded;
use Nowo\CrmKitBundle\Domain\Event\ContactErased;
use Nowo\CrmKitBundle\Domain\Event\CrmMailSent;
use Nowo\CrmKitBundle\Domain\Event\EngagementChanged;
use Nowo\CrmKitBundle\Domain\Event\EngagementDeleted;
use Nowo\CrmKitBundle\Domain\Event\EngagementLogged;
use Nowo\CrmKitBundle\Domain\Event\HostLinkAdded;
use Nowo\CrmKitBundle\Domain\Event\HostLinkRemoved;
use Nowo\CrmKitBundle\Domain\Event\ImportCompleted;
use Nowo\CrmKitBundle\Domain\Event\NoteAdded;
use Nowo\CrmKitBundle\Domain\Event\NoteMentioned;
use Nowo\CrmKitBundle\Domain\Event\NotePinned;
use Nowo\CrmKitBundle\Domain\Event\RecordCreated;
use Nowo\CrmKitBundle\Domain\Event\RecordPurged;
use Nowo\CrmKitBundle\Domain\Event\RecordRestored;
use Nowo\CrmKitBundle\Domain\Event\RecordsMerged;
use Nowo\CrmKitBundle\Domain\Event\RecordsPurged;
use Nowo\CrmKitBundle\Domain\Event\RecordTrashed;
use Nowo\CrmKitBundle\Domain\Event\RecordUpdated;
use Nowo\CrmKitBundle\Domain\Event\RolesChanged;
use Nowo\CrmKitBundle\Domain\Event\TagsChanged;

/**
 * Event names of the CRM: the Symfony layer's own events, and the class names under which the domain events are
 * dispatched after the commit.
 */
final class CrmKitEvents
{
    /**
     * {@see RecordAccessCheckEvent}: before the CRM grants or refuses an action on one company or contact. Listeners
     * may grant() or deny() (a deny wins); without an answer the default decision stands.
     */
    public const RECORD_ACCESS_CHECK = 'nowo_crm_kit.record_access_check';

    /**
     * {@see RecordVisibilityEvent}: with `security.resource_scoping`, which owners' records a person sees in lists,
     * totals, search, exports and on record pages (team rules). Dispatched for every read of several records and every
     * decision on one record (no memo: keep listeners cheap).
     */
    public const RECORD_VISIBILITY = 'nowo_crm_kit.record_visibility';

    // The domain events (contract section 14), dispatched after the commit under their class name; these constants
    // are those names, for `#[AsEventListener(event: CrmKitEvents::CONTACT_ERASED)]` and subscribers (0.2.1).

    /** {@see RecordCreated}: a company or contact was created. */
    public const RECORD_CREATED = RecordCreated::class;

    /** {@see RecordUpdated}: a record changed (the changed field names). */
    public const RECORD_UPDATED = RecordUpdated::class;

    /** {@see RecordTrashed}: a record went to the trash. */
    public const RECORD_TRASHED = RecordTrashed::class;

    /** {@see RecordRestored}: a record came back from the trash. */
    public const RECORD_RESTORED = RecordRestored::class;

    /** {@see RecordPurged}: `purge()` deleted one trashed record for good, with its links, tags, roles and affiliations. */
    public const RECORD_PURGED = RecordPurged::class;

    /** {@see RecordsPurged}: the scheduled retention purge deleted a committed batch of records (list of type + id). */
    public const RECORDS_PURGED = RecordsPurged::class;

    /** {@see RecordsMerged}: type, survivor id, merged id; the merged record's links and affiliations moved to the survivor. */
    public const RECORDS_MERGED = RecordsMerged::class;

    /** {@see ContactErased}: a contact was erased (GDPR) with its links, tags, roles and affiliations. */
    public const CONTACT_ERASED = ContactErased::class;

    /** {@see NoteAdded}: a note was added to a record. */
    public const NOTE_ADDED = NoteAdded::class;

    /** {@see ConsentRecorded}: a consent decision was recorded for a contact. */
    public const CONSENT_RECORDED = ConsentRecorded::class;

    /** {@see ImportCompleted}: an import finished (its counts). */
    public const IMPORT_COMPLETED = ImportCompleted::class;

    /** {@see TagsChanged}: a record's tags changed. */
    public const TAGS_CHANGED = TagsChanged::class;

    /** {@see RolesChanged}: a record's party roles changed (added and removed keys). */
    public const ROLES_CHANGED = RolesChanged::class;

    /** {@see ActorAnonymised}: a host person's references in one tenant were anonymised (the count, never the id). */
    public const ACTOR_ANONYMISED = ActorAnonymised::class;

    /** {@see HostLinkAdded}: a record was linked to a host object. */
    public const HOST_LINK_ADDED = HostLinkAdded::class;

    /** {@see HostLinkRemoved}: a link to a host object was removed by `unlink()` (record type and id, host type and id). */
    public const HOST_LINK_REMOVED = HostLinkRemoved::class;

    /** {@see EngagementLogged}: a call, meeting or e-mail was logged on a record (0.11.0). */
    public const ENGAGEMENT_LOGGED = EngagementLogged::class;

    /** {@see EngagementChanged}: a logged activity was changed (0.11.0). */
    public const ENGAGEMENT_CHANGED = EngagementChanged::class;

    /** {@see EngagementDeleted}: a logged activity was deleted (0.11.0). */
    public const ENGAGEMENT_DELETED = EngagementDeleted::class;

    /** {@see NotePinned}: a note was pinned or unpinned (0.11.0). */
    public const NOTE_PINNED = NotePinned::class;

    /** {@see NoteMentioned}: members were newly mentioned in a note (0.11.0; the ids, never the text). */
    public const NOTE_MENTIONED = NoteMentioned::class;

    /** 0.13.0: an e-mail was sent to a contact from *Write e-mail* (after the sender accepted it). */
    public const MAIL_SENT = CrmMailSent::class;
}
