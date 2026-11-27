<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * A HubSpot engagement kind the import reads (0.12.0, Beacon spec 254 FR-100 A): notes become notes, calls and
 * meetings logged activities, tasks next actions. The value is HubSpot's path segment (`/crm/v3/objects/{value}`).
 * Read with the parent object scope (`crm.objects.contacts.read`, already required by every connection); the
 * granular `crm.objects.{notes,calls,meetings,tasks}.read` scopes are read-only and accepted when a token holds them.
 */
enum HubSpotActivity: string
{
    case Note    = 'notes';
    case Call    = 'calls';
    case Meeting = 'meetings';
    case Task    = 'tasks';

    /**
     * The `object_type` key of its external references (`note`, `call`, `meeting`, `task`).
     */
    public function refType(): string
    {
        return match ($this) {
            self::Note    => 'note',
            self::Call    => 'call',
            self::Meeting => 'meeting',
            self::Task    => 'task',
        };
    }

    /**
     * The `target_type` of its external references: what it becomes in the CRM.
     */
    public function targetType(): string
    {
        return match ($this) {
            self::Note => 'note',
            self::Task => 'next_action',
            default    => 'engagement',
        };
    }

    /**
     * The read scope a token needs (HubSpot's legacy v3 engagement endpoints accept the contacts read scope).
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return ['crm.objects.contacts.read'];
    }

    /**
     * The HubSpot properties the import asks for (fixed, data minimisation): the time, the owner and the content.
     *
     * @return list<string>
     */
    public function properties(): array
    {
        return match ($this) {
            self::Note    => ['hs_timestamp', 'hubspot_owner_id', 'hs_note_body'],
            self::Call    => ['hs_timestamp', 'hubspot_owner_id', 'hs_call_title', 'hs_call_body', 'hs_call_direction', 'hs_call_duration'],
            self::Meeting => ['hs_timestamp', 'hubspot_owner_id', 'hs_meeting_title', 'hs_meeting_body', 'hs_meeting_start_time', 'hs_meeting_end_time', 'hs_meeting_location', 'hs_meeting_outcome'],
            self::Task    => ['hs_timestamp', 'hubspot_owner_id', 'hs_task_subject', 'hs_task_status'],
        };
    }
}
