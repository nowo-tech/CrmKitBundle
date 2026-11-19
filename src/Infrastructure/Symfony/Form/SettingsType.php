<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Per-tenant retention settings.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class SettingsType extends AbstractType
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
            $this->addTypedField('trashDays', IntegerType::class, ['label' => 'settings.form.trash_days', 'attr' => ['min' => 7, 'max' => 365]]);
            $this->addTypedField('timelineYears', IntegerType::class, ['label' => 'settings.form.timeline_years', 'attr' => ['min' => 1, 'max' => 10]]);
            $this->addTypedField('importKeepDays', IntegerType::class, ['label' => 'settings.form.import_keep_days', 'attr' => ['min' => 1, 'max' => 90]]);
            $this->addTypedField('mergeKeepDays', IntegerType::class, ['label' => 'settings.form.merge_keep_days', 'attr' => ['min' => 1, 'max' => 365]]);
            $this->addTypedField('version', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_settings`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_settings',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `crm_settings[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_settings';
    }
}
