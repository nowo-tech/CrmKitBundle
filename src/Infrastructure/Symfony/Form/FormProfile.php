<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

/**
 * FormKit profile of the bundle's forms (REQ-TWIG-005 rule 11), kept next to the form types so they do not depend on
 * the bundle class (REQ-ARCH-002); `NowoCrmKitBundle::FORM_PROFILE` is the same value.
 */
final class FormProfile
{
    /** The profile name. */
    public const NAME = 'crm_kit';
}
