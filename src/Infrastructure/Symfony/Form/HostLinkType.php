<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A link to a host object: type, id and label.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class HostLinkType extends AbstractType
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
            $this->addTypedField('hostType', ChoiceType::class, ['label' => 'link.form.type', 'choices' => $options['types'], 'choice_translation_domain' => false]);
            $this->addTypedField('hostId', TextType::class, ['label' => 'link.form.id', 'attr' => ['maxlength' => 128]]);
            $this->addTypedField('label', TextType::class, ['label' => 'link.form.label', 'required' => false, 'attr' => ['maxlength' => 200]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_link`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_link',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'types'              => [],
        ]);
    }

    /**
     * Field names `crm_link[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_link';
    }
}
