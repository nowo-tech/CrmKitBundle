<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordAudienceInterface;
use Nowo\CrmKitBundle\Domain\Read\MentionCandidate;
use Nowo\CrmKitBundle\Domain\Service\MentionDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function hash_hmac;
use function in_array;
use function is_string;
use function mb_strtolower;
use function mb_substr;
use function preg_match_all;
use function preg_replace_callback;
use function str_contains;
use function substr;
use function trim;
use function usort;

/**
 * The @mentions of notes (0.11.0). A member stands in the text as `@<handle>`: `u` and 24 hexadecimal characters of
 * an HMAC (the kernel secret) of the tenant and the actor id, so the text never holds the id or an e-mail address and a
 * handle means nothing in another tenant. Candidates and notified people are the tenant's members
 * ({@see CrmActorDirectoryInterface::owners()}) who may view the record ({@see CrmRecordAudienceInterface}),
 * re-checked inside the write that saves the note. Stateless.
 */
final readonly class Mentions implements MentionDirectoryInterface
{
    /** Most candidates per answer. */
    public const MAX_CANDIDATES = 10;

    /** Longest query, in characters. */
    public const MAX_QUERY = 100;

    /** Most handles resolved per names call. */
    public const MAX_NAMES = 50;

    /** A handle in a text: not preceded by a handle or e-mail character, not followed by one. */
    private const IN_TEXT = '/(?<![A-Za-z0-9_.@-])@(u[0-9a-f]{24})(?![A-Za-z0-9_])/';

    /**
     * @param string $secret the key of the handles (the kernel secret)
     * @param int $maxPerNote most people one save of a note notifies
     */
    public function __construct(
        private WriteSupport $support,
        private CrmActorDirectoryInterface $directory,
        private CrmRecordAudienceInterface $audience,
        private string $secret,
        private int $maxPerNote = 20,
    ) {
    }

    /**
     * Members who may view the record and match the query.
     */
    public function candidates(SubjectType $type, string $id, string $query): array
    {
        $context = $this->support->access->base();
        $text    = mb_strtolower(trim(mb_substr($query, 0, self::MAX_QUERY)));

        return $this->support->read(function () use ($context, $type, $id, $text): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record  = $this->support->load($context, $type, $id, CrmPermission::Edit);
            $matches = [];
            foreach ($this->members($context->tenant) as $profile) {
                $haystack = mb_strtolower($profile->name . "\n" . ($profile->email ?? ''));
                if ($text === '' || str_contains($haystack, $text)) {
                    $matches[$profile->ref->value] = $profile;
                }
            }
            $allowed = $this->viewers($context->tenant, $record, array_values($matches));
            $out     = [];
            foreach ($matches as $actor => $profile) {
                if (isset($allowed[$actor])) {
                    $out[] = new MentionCandidate($this->handle($context->tenant, (string) $actor), $profile->name);
                }
            }
            usort($out, static fn (MentionCandidate $a, MentionCandidate $b): int => [mb_strtolower($a->name), $a->handle] <=> [mb_strtolower($b->name), $b->handle]);

            return array_slice($out, 0, self::MAX_CANDIDATES);
        });
    }

    /**
     * Names of the tenant's members by handle.
     */
    public function names(SubjectType $type, string $id, array $handles): array
    {
        $context = $this->support->access->base();

        return $this->support->read(function () use ($context, $type, $id, $handles): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->load($context, $type, $id, CrmPermission::View, anyStatus: true);
            $labels = $this->labels($context->tenant);
            $out    = [];
            foreach (array_slice($handles, 0, self::MAX_NAMES) as $handle) {
                if (is_string($handle) && isset($labels[$handle])) {
                    $out[$handle] = $labels[$handle];
                }
            }

            return $out;
        });
    }

    /**
     * The handle of a member in a tenant.
     */
    public function handle(TenantRef $tenant, string $actor): string
    {
        return 'u' . substr(hash_hmac('sha256', "crm-mention\0" . $tenant->value . "\0" . $actor, $this->secret), 0, 24);
    }

    /**
     * The handles written in a text (distinct, in order).
     *
     * @return list<string>
     */
    public static function handles(string $text): array
    {
        preg_match_all(self::IN_TEXT, $text, $found);
        $out = [];
        foreach ($found[1] as $handle) {
            if (!in_array($handle, $out, true)) {
                $out[] = $handle;
            }
        }

        return $out;
    }

    /**
     * The names of the tenant's members by handle (one directory call), for showing notes.
     *
     * @return array<string, string>
     */
    public function labels(TenantRef $tenant): array
    {
        $labels = [];
        foreach ($this->members($tenant) as $profile) {
            $labels[$this->handle($tenant, $profile->ref->value)] = $profile->name;
        }

        return $labels;
    }

    /**
     * A text with its known handles shown as `@Name` (plain text: print it escaped).
     *
     * @param array<string, string> $labels {@see self::labels()}
     */
    public static function display(string $text, array $labels): string
    {
        if ($labels === []) {
            return $text;
        }

        return (string) preg_replace_callback(self::IN_TEXT, static fn (array $m): string => isset($labels[$m[1]]) ? '@' . $labels[$m[1]] : $m[0], $text);
    }

    /**
     * The members newly mentioned in `$body` (not in `$before`), inside the write: members of the tenant, never the
     * author, only people who may view the record, at most `maxPerNote`.
     *
     * @return list<ActorRef>
     */
    public function recipients(CrmContext $context, CrmRecord $record, ?string $before, string $body): array
    {
        $old = $before === null ? [] : self::handles($before);
        $new = [];
        foreach (self::handles($body) as $handle) {
            if (!in_array($handle, $old, true)) {
                $new[$handle] = true;
            }
        }
        if ($new === []) {
            return [];
        }
        $mentioned = [];
        foreach ($this->members($context->tenant) as $profile) {
            if ($profile->ref->value !== $context->actor->value && isset($new[$this->handle($context->tenant, $profile->ref->value)])) {
                $mentioned[$profile->ref->value] = $profile;
            }
        }
        $allowed = $this->viewers($context->tenant, $record, array_values($mentioned));
        $out     = [];
        foreach (array_keys($mentioned) as $actor) {
            if (isset($allowed[(string) $actor]) && count($out) < $this->maxPerNote) {
                $out[] = new ActorRef((string) $actor);
            }
        }

        return $out;
    }

    /**
     * The tenant's members, without the anonymised placeholder.
     *
     * @return list<ActorProfile>
     */
    private function members(TenantRef $tenant): array
    {
        $out = [];
        foreach ($this->directory->owners($tenant) as $profile) {
            if (!ActorRef::isReserved($profile->ref->value)) {
                $out[] = $profile;
            }
        }

        return $out;
    }

    /**
     * The actor ids among the profiles the audience lets view the record (exact matches only).
     *
     * @param list<ActorProfile> $profiles
     *
     * @return array<string, true>
     */
    private function viewers(TenantRef $tenant, CrmRecord $record, array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }
        $asked = [];
        foreach ($profiles as $profile) {
            $asked[$profile->ref->value] = true;
        }
        $refs    = array_values(array_map(static fn (ActorProfile $p): ActorRef => $p->ref, $profiles));
        $allowed = [];
        foreach ($this->audience->viewers($tenant, SubjectRef::of($record::type(), $record->getId(), $record->getOwnerId()), $refs) as $ref) {
            if (isset($asked[$ref->value]) && !ActorRef::isReserved($ref->value)) {
                $allowed[$ref->value] = true;
            }
        }

        return $allowed;
    }
}
