<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Moving a deal to a stage (0.3.0): the stage, the lost reason and note (for a lost stage) and the version.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class StageMoveType extends AbstractType
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
            $this->addTypedField('stage', ChoiceType::class, ['label' => 'deal.move.stage', 'choices' => $options['stages'], 'choice_translation_domain' => false]);
            $this->addTypedField('lost_reason', ChoiceType::class, ['label' => 'deal.move.lost_reason', 'choices' => $options['reasons'], 'required' => false, 'placeholder' => 'deal.form.none']);
            $this->addTypedField('lost_note', TextareaType::class, ['label' => 'deal.move.lost_note', 'required' => false, 'attr' => ['maxlength' => 500, 'rows' => 2]]);
            $this->addTypedField('version', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_stage_move`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_stage_move',
            'stages'             => [],
            'reasons'            => [],
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `crm_stage_move[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_stage_move';
    }
}
