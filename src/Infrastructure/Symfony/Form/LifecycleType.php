<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The lifecycle stage of a company or contact in a party funnel (0.3.0): one choice `<funnel id>|<stage id>`.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class LifecycleType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void {
            $this->addTypedField('stage', ChoiceType::class, ['label' => 'lifecycle.form.stage', 'choices' => $options['stages'], 'choice_translation_domain' => false]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_lifecycle`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_lifecycle',
            'stages'             => [],
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `crm_lifecycle[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_lifecycle';
    }
}
