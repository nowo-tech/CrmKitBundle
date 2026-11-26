<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A note: plain text (up to 10 000 characters) and the pin.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class NoteType extends AbstractType
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
            // 0.11.0: with @mentions, MilkdownEditor's field (`body_type`) with the CRM's candidate URLs (`body_options`).
            /** @var class-string<FormTypeInterface<mixed>> $type */
            $type = $options['body_type'];
            /** @var array<string, mixed> $extra */
            $extra = $options['body_options'];
            $this->addTypedField('body', $type, ['label' => 'note.form.body', 'attr' => ['maxlength' => 10000, 'rows' => 4]] + $extra);
            $this->addTypedField('pinned', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'note.form.pinned', 'required' => false]);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_note`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_note',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'versioned'          => false,
            'body_type'          => TextareaType::class,
            'body_options'       => [],
        ]);
        $resolver->setAllowedTypes('body_type', 'string');
        $resolver->setAllowedTypes('body_options', 'array');
    }

    /**
     * Field names `crm_note[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_note';
    }
}
