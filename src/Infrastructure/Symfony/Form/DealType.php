<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A deal (0.3.0): title, company, contact, owner, expected close; with `amounts` the amount, currency and probability override; on create the funnel stage, on edit the version.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class DealType extends AbstractType
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
            $this->addTypedField('title', TextType::class, ['label' => 'deal.form.title', 'attr' => ['maxlength' => 200]]);
            if ($options['versioned'] !== true) {
                $this->addTypedField('stage', ChoiceType::class, ['label' => 'deal.form.stage', 'choices' => $options['stages'], 'choice_translation_domain' => false]);
            }
            $this->addTypedField('company', ChoiceType::class, ['label' => 'deal.form.company', 'choices' => $options['companies'], 'required' => false, 'placeholder' => 'deal.form.none', 'choice_translation_domain' => false]);
            $this->addTypedField('contact', ChoiceType::class, ['label' => 'deal.form.contact', 'choices' => $options['contacts'], 'required' => false, 'placeholder' => 'deal.form.none', 'choice_translation_domain' => false]);
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'deal.form.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'deal.form.none', 'choice_translation_domain' => false]);
            if ($options['amounts'] === true) {
                $this->addTypedField('amount', TextType::class, ['label' => 'deal.form.amount', 'required' => false, 'attr' => ['maxlength' => 20, 'inputmode' => 'decimal']]);
                $this->addTypedField('currency', TextType::class, ['label' => 'deal.form.currency', 'required' => false, 'attr' => ['maxlength' => 3]]);
                $this->addTypedField('probability', IntegerType::class, ['label' => 'deal.form.probability', 'required' => false, 'attr' => ['min' => 0, 'max' => 100]]);
            }
            $this->addTypedField('expected_close', DateType::class, ['label' => 'deal.form.expected_close', 'required' => false, 'widget' => 'single_text', 'input' => 'string']);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_deal`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_deal',
            'versioned'          => false,
            'amounts'            => false,
            'stages'             => [],
            'companies'          => [],
            'contacts'           => [],
            'owners'             => [],
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `crm_deal[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_deal';
    }
}
