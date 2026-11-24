<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The filters of the funnel statistics (0.3.0, GET): a past instant, owner, role, tag and the period of the closed deals.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class StatsFilterType extends AbstractType
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
            // 0.10.2 (Beacon filter standard): a native date field; the controller takes the snapshot at the end of that
            // day in the application's time zone (now, for today).
            $this->addTypedField('at', DateType::class, ['label' => 'stats.filter.at', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'model_timezone' => 'UTC', 'view_timezone' => 'UTC']);
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'list.filter.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'list.filter.any_owner', 'choice_translation_domain' => false]);
            if ($options['roles'] !== []) {
                $this->addTypedField('role', ChoiceType::class, ['label' => 'list.filter.role', 'choices' => $options['roles'], 'required' => false, 'placeholder' => 'list.filter.any_role']);
            }
            $this->addTypedField('tag', ChoiceType::class, ['label' => 'list.filter.tag', 'choices' => $options['tags'], 'required' => false, 'placeholder' => 'list.filter.any_tag', 'choice_translation_domain' => false]);
            $this->addTypedField('from', DateType::class, ['label' => 'stats.filter.from', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable']);
            $this->addTypedField('to', DateType::class, ['label' => 'stats.filter.to', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable']);
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
            'method'             => 'GET',
            'owners'             => [],
            'roles'              => [],
            'tags'               => [],
            'allow_extra_fields' => true,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `at, owner, role, tag, from, to`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
