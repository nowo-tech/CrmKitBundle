<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A consent decision: purpose, granted or withdrawn, evidence.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class ConsentType extends AbstractType
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
            $this->addTypedField('purpose', ChoiceType::class, ['label' => 'consent.form.purpose', 'choices' => $options['purposes'], 'choice_translation_domain' => TranslationDomain::NAME]);
            $this->addTypedField('granted', ChoiceType::class, ['label' => 'consent.form.decision', 'choices' => ['consent.granted' => '1', 'consent.withdrawn' => '0'], 'expanded' => true]);
            $this->addTypedField('evidence', TextType::class, ['label' => 'consent.form.evidence', 'required' => false, 'help' => 'consent.form.evidence_help', 'attr' => ['maxlength' => 255]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_consent`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_consent',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'purposes'           => [],
        ]);
    }

    /**
     * Field names `crm_consent[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_consent';
    }
}
