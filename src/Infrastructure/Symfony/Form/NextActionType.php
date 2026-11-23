<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A next action (0.3.0): title, due time, assignee, and the dedupe key of the rendered form (a double submit stores one action).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class NextActionType extends AbstractType
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
            $this->addTypedField('title', TextType::class, ['label' => 'next_action.form.title', 'attr' => ['maxlength' => 200]]);
            $this->addTypedField('due_at', DateTimeType::class, ['label' => 'next_action.form.due_at', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'model_timezone' => 'UTC']);
            $this->addTypedField('assignee', ChoiceType::class, ['label' => 'next_action.form.assignee', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'next_action.form.me', 'choice_translation_domain' => false]);
            $this->addTypedField('dedupe', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_next_action`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_next_action',
            'owners'             => [],
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `crm_next_action[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_next_action';
    }
}
