<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Connect or replace the tenant's HubSpot credential (0.10.0): a service key (or a legacy private app token) in a
 * password field that is never pre-filled nor re-rendered, an optional label, the confirmation of an account change
 * and the connection's version.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class HubSpotConnectType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('token', PasswordType::class, ['label' => 'import.hubspot.form.token', 'help' => 'import.hubspot.form.token_help', 'always_empty' => true, 'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false']]);
            $this->addTypedField('label', TextType::class, ['label' => 'import.hubspot.form.label', 'required' => false, 'attr' => ['maxlength' => 80]]);
            $this->addTypedField('confirm_account_change', CheckboxType::class, ['label' => 'import.hubspot.form.confirm_account_change', 'required' => false]);
            $this->addTypedField('version', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_hubspot_connect`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_hubspot_connect',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `crm_hubspot_connect[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_hubspot_connect';
    }
}
