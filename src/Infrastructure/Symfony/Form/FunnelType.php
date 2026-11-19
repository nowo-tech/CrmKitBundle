<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A funnel (0.3.0): its name; on create its kind (deal, or a lifecycle of companies or contacts), on edit the archive flag.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class FunnelType extends AbstractType
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
            $this->addTypedField('name', TextType::class, ['label' => 'funnel.form.name', 'attr' => ['maxlength' => 120]]);
            if ($options['versioned'] === true) {
                $this->addTypedField('archived', CheckboxType::class, ['label' => 'funnel.form.archived', 'required' => false]);
                $this->addTypedField('version', HiddenType::class, []);
            } else {
                $this->addTypedField('kind', ChoiceType::class, ['label' => 'funnel.form.kind', 'choices' => ['funnel.kind.deal' => 'deal', 'funnel.kind.party_company' => 'party_company', 'funnel.kind.party_contact' => 'party_contact']]);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_funnel`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_funnel',
            'versioned'          => false,
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `crm_funnel[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_funnel';
    }
}
