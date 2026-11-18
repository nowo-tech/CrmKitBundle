<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * A CRM for Symfony organisations, phase by phase (docs/CONTRACT.md). This is the scaffold: phase A (companies and
 * contacts) arrives with 0.1.0.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoCrmKitBundle extends AbstractBundle
{
    /** Configuration root and container parameter prefix. */
    public const ALIAS = 'nowo_crm_kit';

    /** Translation domain and Twig namespace. */
    public const TRANSLATION_DOMAIN = 'NowoCrmKitBundle';

    protected string $extensionAlias = self::ALIAS;

    /**
     * The bundle's resources live in `src/Resources`.
     */
    public function getPath(): string
    {
        return __DIR__;
    }
}
