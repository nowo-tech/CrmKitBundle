<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;

/**
 * `{{ me.* }}` (0.13.0): the person who renders the message, as the host's actor directory shows them (name, and the
 * e-mail when the host shares it). MailKit ships no `me` provider (its contract C5): this one owns the namespace.
 */
final class MePlaceholders extends CrmPlaceholders
{
    /** Names offered (name ⇒ label key). */
    public const NAMES = [
        'name'  => 'template.placeholder.me_name',
        'email' => 'template.placeholder.me_email',
    ];

    /**
     * Wires the directory.
     */
    public function __construct(CrmTemplateActors $actors, private readonly CrmActorDirectoryInterface $directory)
    {
        parent::__construct($actors);
    }

    /**
     * `me`.
     */
    public static function placeholderNamespace(): string
    {
        return 'me';
    }

    /**
     * {@inheritDoc}
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * {@inheritDoc}
     */
    protected function values(CrmContext $context, PlaceholderContext $placeholders, array $names): array
    {
        $profile = $this->directory->actor($context->tenant, $context->actor);

        return $profile instanceof ActorProfile ? ['name' => $profile->name, 'email' => $profile->email] : [];
    }
}
