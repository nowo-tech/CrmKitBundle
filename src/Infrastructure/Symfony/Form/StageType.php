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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A funnel stage (0.3.0): name, default probability, age limit in days; on create its kind (fixed afterwards), on edit the archive flag.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class StageType extends AbstractType
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
            $this->addTypedField('name', TextType::class, ['label' => 'stage.form.name', 'attr' => ['maxlength' => 80]]);
            if ($options['versioned'] !== true) {
                $this->addTypedField('kind', ChoiceType::class, ['label' => 'stage.form.kind', 'choices' => $options['kinds']]);
            }
            $this->addTypedField('probability', IntegerType::class, ['label' => 'stage.form.probability', 'attr' => ['min' => 0, 'max' => 100]]);
            $this->addTypedField('rot_days', IntegerType::class, ['label' => 'stage.form.rot_days', 'required' => false, 'attr' => ['min' => 1, 'max' => 3650]]);
            if ($options['versioned'] === true) {
                $this->addTypedField('archived', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'stage.form.archived', 'required' => false]);
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_stage`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_stage',
            'versioned'          => false,
            'kinds'              => ['stage.kind.open' => 'open', 'stage.kind.won' => 'won', 'stage.kind.lost' => 'lost'],
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `crm_stage[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_stage';
    }
}
