<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A contact's company, role, primary-company flag, purposes and the company's primary-contact flag.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class AffiliationType extends AbstractType
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
            $this->addTypedField('company', ChoiceType::class, ['label' => 'affiliation.form.company', 'choices' => $options['companies'], 'choice_translation_domain' => false]);
            $this->addTypedField('role', TextType::class, ['label' => 'affiliation.form.role', 'required' => false, 'attr' => ['maxlength' => 120]]);
            $this->addTypedField('primary', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'affiliation.form.primary', 'required' => false]);
            if ($options['purposes'] !== []) {
                $this->addTypedField('purposes', ChoiceType::class, ['label' => 'affiliation.form.purposes', 'choices' => $options['purposes'], 'multiple' => true, 'expanded' => true, 'required' => false]);
            }
            $this->addTypedField('primary_contact', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'affiliation.form.primary_contact', 'required' => false]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_affiliation`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_affiliation',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'companies'          => [],
            'purposes'           => [],
        ]);
    }

    /**
     * Field names `crm_affiliation[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_affiliation';
    }
}
