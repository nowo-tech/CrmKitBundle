<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The filters of a list (GET, no CSRF).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class FilterType extends AbstractType
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
            $this->addTypedField('q', SearchType::class, ['label' => 'list.filter.q', 'required' => false, 'attr' => ['maxlength' => 100]]);
            $this->addTypedField('tag', ChoiceType::class, ['label' => 'list.filter.tag', 'choices' => $options['tags'], 'required' => false, 'placeholder' => 'list.filter.any', 'choice_translation_domain' => false]);
            if ($options['roles'] !== []) {
                $this->addTypedField('role', ChoiceType::class, ['label' => 'list.filter.role', 'choices' => $options['roles'], 'required' => false, 'placeholder' => 'list.filter.any']);
            }
            if ($options['purposes'] !== []) {
                $this->addTypedField('purpose', ChoiceType::class, ['label' => 'list.filter.purpose', 'choices' => $options['purposes'], 'required' => false, 'placeholder' => 'list.filter.any']);
                $this->addTypedField('primary_contact', ChoiceType::class, ['label' => 'list.filter.primary_contact', 'choices' => ['list.filter.primary_contact_only' => '1'], 'required' => false, 'placeholder' => 'list.filter.any']);
            }
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'list.filter.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'list.filter.any', 'choice_translation_domain' => false]);
            $this->addTypedField('status', ChoiceType::class, ['label' => 'list.filter.status', 'choices' => ['list.status.active' => 'active', 'list.status.trashed' => 'trashed'], 'required' => false, 'placeholder' => false]);
        });
    }

    /**
     * Array data, no CSRF (a GET filter).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'method'             => 'GET',
            'tags'               => [],
            'owners'             => [],
            'roles'              => [],
            'purposes'           => [],
        ]);
    }

    /**
     * Field names `q, tag, role, purpose, primary_contact, owner, status[...]`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
