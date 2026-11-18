<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Picks the other record of a merge (its id).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class MergePickType extends AbstractType
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
            $this->addTypedField('other', TextType::class, ['label' => 'merge.form.other', 'help' => 'merge.form.other_help', 'attr' => ['maxlength' => 36]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_merge_pick`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_merge_pick',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `crm_merge_pick[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_merge_pick';
    }
}
