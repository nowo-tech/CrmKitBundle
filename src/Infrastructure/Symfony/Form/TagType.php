<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_string;

/**
 * A tag: name and UiKit palette colour.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class TagType extends AbstractType
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
            $this->addTypedField('name', TextType::class, ['label' => 'tag.form.name', 'attr' => ['maxlength' => 60]]);
            $this->addTypedField('colour', ChoiceType::class, ['label' => 'tag.form.colour', 'choices' => $options['colours'], 'choice_translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN, 'expanded' => true, 'row_attr' => ['class' => 'crm-kit-swatches'], 'choice_attr' => static fn (mixed $colour): array => ['class' => 'crm-kit-swatch-input crm-kit-tag--' . (is_string($colour) ? $colour : 'neutral'), 'data-colour' => is_string($colour) ? $colour : 'neutral']]);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_tag`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_tag',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'versioned'          => false,
            'colours'            => [],
        ]);
    }

    /**
     * Field names `crm_tag[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_tag';
    }
}
