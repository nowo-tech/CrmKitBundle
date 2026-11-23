<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain;

/**
 * Translation domain of the bundle copy (REQ-I18N-003): the catalogue a host overrides. It lives in the Domain layer so
 * every layer (UI, bridges) reads it without depending on the bundle class (REQ-ARCH-002);
 * `NowoCrmKitBundle::TRANSLATION_DOMAIN` is the same value.
 */
final class TranslationDomain
{
    /** The domain name (also the Twig namespace, REQ-TWIG-002). */
    public const NAME = 'NowoCrmKitBundle';
}
