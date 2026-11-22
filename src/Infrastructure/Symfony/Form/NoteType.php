<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A note: plain text (up to 10 000 characters) and the pin.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
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
            $this->addTypedField('body', TextareaType::class, ['label' => 'note.form.body', 'attr' => ['maxlength' => 10000, 'rows' => 4]]);
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
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'versioned'          => false,
        ]);
    }

    /**
     * Field names `crm_note[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_note';
    }
}
