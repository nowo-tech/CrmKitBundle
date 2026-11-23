<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function in_array;

/**
 * What the test ports answer: the tenant, the actor, the grants (`view`, or `edit:<record id>`), the owners per
 * tenant, the feature switch, the plan limits, and the race switches of {@see RaceInjector}.
 */
final class TestContext
{
    public ?string $tenant = 'acme';

    public ?string $actor = 'ana';

    /** @var array<string, array<string, list<string>>> tenant => actor => grants */
    public array $grants = [];

    /** @var array<string, array<string, string>> tenant => actor => name */
    public array $owners = [];

    /** @var array<string, bool> tenant => feature on (default on) */
    public array $enabled = [];

    /** @var array<string, int> resource => limit */
    public array $limits = [];

    /** Bump the version of the next updated record just before our flush (a racing writer saved first). */
    public bool $bumpVersions = false;

    /** Removing the next company or contact throws (a batch that fails inside its transaction). */
    public bool $failOnRemove = false;

    /** Store a contact with the same e-mail just before ours (a racing writer stored it first). */
    public int $emailRaces = 0;

    /**
     * Rows a racing writer stores just before ours, per entity short name (`FieldDefinition`, `HostLink`,
     * `Affiliation`, `CrmSettings`, `Tag`, `Tagging`, `TagRename`): how many times.
     *
     * @var array<string, int>
     */
    public array $races = [];

    /**
     * Refusals that win over every grant: `<permission>:<record id>` (a person with `edit` but not on that record).
     *
     * @var list<string>
     */
    public array $denied = [];

    /** @var list<array{string, string, int}> tenant, text and limit of each call of the test tickets' search() */
    public array $ticketSearches = [];

    /** @var list<string> channel|token of the record events seen (API tests) */
    public array $seenChannels = [];

    /** A panel provider throws. */
    public bool $panelFails = false;

    /** A panel provider throws a transient storage failure. */
    public bool $panelTransient = false;

    /**
     * Grants permissions (or `<permission>:<record id>`) to an actor of a tenant.
     */
    public function grant(string $tenant, string $actor, string ...$grants): self
    {
        foreach ($grants as $grant) {
            $this->grants[$tenant][$actor][] = $grant;
        }

        return $this;
    }

    /**
     * Whether the actor holds the permission (tenant-wide, or on the subject).
     */
    public function holds(string $tenant, string $actor, CrmPermission $permission, ?SubjectRef $subject): bool
    {
        // The grants of the tests use the five permissions of 0.1.0: create / delete / import are held as edit, export
        // and erase as manage.
        $permission = match ($permission) {
            CrmPermission::Create, CrmPermission::Delete, CrmPermission::Import => CrmPermission::Edit,
            CrmPermission::Export, CrmPermission::Erase                         => CrmPermission::Manage,
            default                                                             => $permission,
        };
        $mine = $this->grants[$tenant][$actor] ?? [];
        if ($subject instanceof SubjectRef && in_array($permission->value . ':' . $subject->id->value, $this->denied, true)) {
            return false;
        }

        return in_array($permission->value, $mine, true) || ($subject instanceof SubjectRef && in_array($permission->value . ':' . $subject->id->value, $mine, true));
    }
}
