<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/notify-kit-bundle's contract (0.3+), loaded by tests/bootstrap.php only when NotifyKit
// is not installed: the names and constructor signatures the CRM's NotifyKit bridge uses (src/Bridge/NotifyKit). The
// guards are simplified; the bridge passes values NotifyKit's own guards accept.

namespace Nowo\NotifyKitBundle\Contract;

use InvalidArgumentException;

use function preg_match;
use function str_starts_with;

enum Urgency: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';
}

final readonly class RecipientRef
{
    public function __construct(public string $type, public string $id)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $type) !== 1 || $id === '') {
            throw new InvalidArgumentException('Invalid RecipientRef.');
        }
    }
}

final readonly class ContextRef
{
    public function __construct(public string $type, public string $id)
    {
    }
}

final readonly class NotificationRequest
{
    /**
     * @param list<RecipientRef> $recipients
     * @param array<string, string> $params
     * @param list<RecipientRef> $involved
     */
    public function __construct(
        public string $type,
        public array $recipients,
        public ?RecipientRef $actor,
        public ?ContextRef $context,
        public string $target,
        public string $url,
        public array $params = [],
        public Urgency $urgency = Urgency::Normal,
        public array $involved = [],
        public ?string $dedupeKey = null,
    ) {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            throw new InvalidArgumentException('NotificationRequest url must be a local path.');
        }
    }
}

final readonly class NotifyResult
{
    public function __construct(public int $written = 0)
    {
    }
}

interface NotifierInterface
{
    public function notify(NotificationRequest $request): NotifyResult;
}

final readonly class TypeDefinition
{
    public const IN_APP = 'in_app';

    /**
     * @param array<string, bool> $defaultChannels
     * @param list<string> $lockedChannels
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $icon,
        public string $titleKey,
        public ?string $permission,
        public array $defaultChannels,
        public array $lockedChannels = [self::IN_APP],
        public Urgency $urgency = Urgency::Normal,
        public bool $coalesce = true,
        public bool $inMatrix = true,
        public ?string $titleDomain = null,
    ) {
    }
}

final readonly class CategoryDefinition
{
    public function __construct(public string $key, public string $labelKey, public string $group = 'general', public int $order = 0)
    {
    }
}

interface TypeProviderInterface
{
    /**
     * @return iterable<TypeDefinition>
     */
    public function types(): iterable;

    /**
     * @return iterable<CategoryDefinition>
     */
    public function categories(): iterable;
}
