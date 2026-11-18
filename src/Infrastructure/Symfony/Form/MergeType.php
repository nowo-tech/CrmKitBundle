<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The fields to take from the other record, with both versions shown.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class MergeType extends AbstractType
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
            /** @var list<string> $list */
            $list = $options['fields'];
            foreach ($list as $i => $label) {
                $this->addTypedField('take_' . $i, CheckboxType::class, ['label' => $label, 'translation_domain' => false, 'required' => false]);
            }
            $this->addTypedField('other', HiddenType::class, []);
            $this->addTypedField('survivorVersion', HiddenType::class, []);
            $this->addTypedField('otherVersion', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_merge`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_merge',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'fields'             => [],
        ]);
    }

    /**
     * Field names `crm_merge[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_merge';
    }
}
