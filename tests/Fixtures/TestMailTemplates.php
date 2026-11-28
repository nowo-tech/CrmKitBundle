<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use DateTimeImmutable;
use LogicException;
use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Exception\TemplateVersionConflict;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;
use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Nowo\MailKitBundle\Template\Value\RenderedTemplate;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Template\Value\TemplateSummary;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

use function array_keys;
use function array_unique;
use function array_values;
use function mb_strlen;
use function preg_match_all;
use function preg_replace_callback;
use function str_contains;
use function substr;
use function usort;

/**
 * An in-memory stand-in of MailKit 2.7's template library and renderer for the CRM's tests: the same visibility rules
 * (tenant, personal rows to their owner, the permission port), version checks, key uniqueness, the deleted event and a
 * closed `{{ ns.name }}` substitution through the tagged providers (unknown placeholders stay as text). A text body
 * longer than 5 000 characters is refused as MailKit's `InvalidTemplate('body')`; a name `raise:<field>` raises
 * `InvalidTemplate(<field>)`.
 */
final class TestMailTemplates implements TemplateLibraryInterface, TemplateRendererInterface
{
    /** @var array<string, StoredTemplate> */
    public array $rows = [];

    /** @var list<PlaceholderProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<PlaceholderProviderInterface> $providers
     */
    public function __construct(private readonly TemplatePermissionInterface $permission, private readonly EventDispatcherInterface $events, iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    public function list(TemplateActor $actor, TemplateKind $kind, ?TemplateScope $scope = null, ?string $locale = null): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->kind === $kind && (!$scope instanceof TemplateScope || $row->scope === $scope) && $this->visible($actor, $row)) {
                $out[] = $row->summary();
            }
        }
        usort($out, static fn (TemplateSummary $a, TemplateSummary $b): int => [$a->scope === TemplateScope::Shared, $a->key] <=> [$b->scope === TemplateScope::Shared, $b->key]);

        return $out;
    }

    public function get(TemplateActor $actor, string $id): StoredTemplate
    {
        $row = $this->rows[$id] ?? null;
        if (!$row instanceof StoredTemplate || !$this->visible($actor, $row)) {
            throw new TemplateNotFound();
        }

        return $row;
    }

    public function findByKey(TemplateActor $actor, TemplateKind $kind, TemplateScope $scope, string $key, string $locale): StoredTemplate
    {
        foreach ($this->rows as $row) {
            if ($row->kind === $kind && $row->scope === $scope && $row->key === $key && $row->locale === $locale && $this->visible($actor, $row)) {
                return $row;
            }
        }

        throw new TemplateNotFound();
    }

    public function create(TemplateActor $actor, TemplateDraft $draft): StoredTemplate
    {
        $this->manage($actor, $draft->scope);
        $this->check($draft);
        $owner = $draft->scope === TemplateScope::Personal ? $actor->owner : null;
        foreach ($this->rows as $row) {
            if ($row->tenant === $actor->tenant && $row->kind === $draft->kind && $row->scope === $draft->scope && $row->owner === $owner && $row->key === $draft->key && $row->locale === $draft->locale) {
                throw new TemplateKeyTaken();
            }
        }
        $now = new DateTimeImmutable('2026-11-18 10:00:00');
        $id  = Uuid::v7()->toRfc4122();

        return $this->rows[$id] = new StoredTemplate($id, $draft->kind, $draft->scope, $actor->tenant, $owner, $draft->key, $draft->name, $draft->locale, $draft->subject, $draft->html, $draft->text, 1, $actor->owner, $actor->owner, $now, $now);
    }

    public function update(TemplateActor $actor, string $id, int $expectedVersion, TemplateDraft $draft): StoredTemplate
    {
        $row = $this->get($actor, $id);
        $this->manage($actor, $row->scope);
        $this->check($draft);
        if ($row->version !== $expectedVersion) {
            throw new TemplateVersionConflict($expectedVersion);
        }

        return $this->rows[$id] = new StoredTemplate($id, $row->kind, $row->scope, $row->tenant, $row->owner, $draft->key, $draft->name, $draft->locale, $draft->subject, $draft->html, $draft->text, $row->version + 1, $row->createdBy, $actor->owner, $row->createdAt, $row->updatedAt);
    }

    public function delete(TemplateActor $actor, string $id, int $expectedVersion): void
    {
        $row = $this->get($actor, $id);
        $this->manage($actor, $row->scope);
        if ($row->version !== $expectedVersion) {
            throw new TemplateVersionConflict($expectedVersion);
        }
        unset($this->rows[$id]);
        $this->events->dispatch(new TemplateDeletedEvent($id, $row->tenant, $row->kind, $row->scope));
    }

    public function render(TemplateActor $actor, string $id, array $subjects = []): RenderedTemplate
    {
        $row     = $this->get($actor, $id);
        $context = new PlaceholderContext($actor, $row->locale, $subjects);
        $names   = [];
        foreach ([$row->subject, $row->text] as $part) {
            preg_match_all('/\{\{\s{0,8}([a-z][a-z0-9_]{0,31})\.([a-z0-9_]{1,40})\s{0,8}\}\}/', $part, $found);
            foreach (array_keys($found[0]) as $i) {
                $names[$found[1][$i]][] = $found[2][$i];
            }
        }
        $values = [];
        foreach ($this->providers as $provider) {
            $namespace = $provider::placeholderNamespace();
            if (isset($names[$namespace])) {
                foreach ($provider->resolve(array_values(array_unique($names[$namespace])), $context) as $name => $value) {
                    $values[$namespace . '.' . $name] = $value;
                }
            }
        }
        $unresolved = [];
        $replace    = static function (string $part) use ($values, &$unresolved): string {
            return (string) preg_replace_callback('/\{\{\s{0,8}([a-z][a-z0-9_]{0,31}\.[a-z0-9_]{1,40})\s{0,8}\}\}/', static function (array $m) use ($values, &$unresolved): string {
                if (isset($values[$m[1]])) {
                    return $values[$m[1]];
                }
                $unresolved[] = $m[1];

                return $m[0];
            }, $part);
        };
        $subject = $replace($row->subject);
        $text    = $replace($row->text);

        return new RenderedTemplate($row->id, $row->kind, $row->key, $row->locale, $subject, '', $text, array_values(array_unique($unresolved)));
    }

    public function renderDraft(TemplateActor $actor, TemplateDraft $draft, array $subjects = []): RenderedTemplate
    {
        throw new LogicException('Not used by the CRM.');
    }

    public function placeholders(): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            $out[$provider::placeholderNamespace()] = $provider->definitions();
        }

        return $out;
    }

    /**
     * Stores a row as another tenant or person would (no permission check).
     */
    public function seed(string $tenant, ?string $owner, TemplateKind $kind, string $key, string $subject, string $text, string $html = ''): string
    {
        $now             = new DateTimeImmutable('2026-11-18 10:00:00');
        $id              = Uuid::v7()->toRfc4122();
        $this->rows[$id] = new StoredTemplate($id, $kind, $owner === null ? TemplateScope::Shared : TemplateScope::Personal, $tenant, $owner, $key, 'Seed ' . $key, 'en', $subject, $html, $text, 1, $owner, $owner, $now, $now);

        return $id;
    }

    private function visible(TemplateActor $actor, StoredTemplate $row): bool
    {
        if ($row->tenant !== $actor->tenant) {
            return false;
        }
        if ($row->scope === TemplateScope::Personal) {
            return $row->owner === $actor->owner && $this->permission->isGranted(TemplatePermission::Personal, $actor);
        }

        return $this->permission->isGranted(TemplatePermission::UseShared, $actor) || $this->permission->isGranted(TemplatePermission::ManageShared, $actor);
    }

    private function manage(TemplateActor $actor, TemplateScope $scope): void
    {
        $permission = $scope === TemplateScope::Shared ? TemplatePermission::ManageShared : TemplatePermission::Personal;
        if (!$this->permission->isGranted($permission, $actor)) {
            throw new TemplateAccessDenied($permission);
        }
    }

    private function check(TemplateDraft $draft): void
    {
        if (str_contains($draft->name, 'raise:')) {
            throw new InvalidTemplate(substr($draft->name, 6), 'Refused.');
        }
        if (mb_strlen($draft->text) > 5000) {
            throw new InvalidTemplate('body', 'Too long.');
        }
    }
}
