<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A custom field definition (the key and type are fixed once defined).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class FieldDefinitionType extends AbstractType
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
            $this->addTypedField('key', TextType::class, ['label' => 'field.form.key', 'disabled' => $options['versioned'] === true, 'help' => 'field.form.key_help', 'attr' => ['maxlength' => 40]]);
            $this->addTypedField('label', TextType::class, ['label' => 'field.form.label', 'attr' => ['maxlength' => 80]]);
            $this->addTypedField('type', ChoiceType::class, ['label' => 'field.form.type', 'disabled' => $options['versioned'] === true, 'choices' => $options['types']]);
            $this->addTypedField('choices', TextareaType::class, ['label' => 'field.form.choices', 'required' => false, 'help' => 'field.form.choices_help', 'attr' => ['rows' => 4]]);
            $this->addTypedField('required', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'field.form.required', 'required' => false]);
            $this->addTypedField('position', IntegerType::class, ['label' => 'field.form.position', 'attr' => ['min' => 0, 'max' => 1000]]);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_field`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_field',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'versioned'          => false,
            'types'              => [],
        ]);
    }

    /**
     * Field names `crm_field[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_field';
    }
}
