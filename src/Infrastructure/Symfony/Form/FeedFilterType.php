<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\Enum\FeedKind;
use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The filter of a record's activity feed (0.11.0, GET): the kind and the person who wrote or logged the entries.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class FeedFilterType extends AbstractType
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
            $kinds = [];
            foreach (FeedKind::cases() as $kind) {
                $kinds['activity_log.filter.kind_' . $kind->value] = $kind->value;
            }
            $this->addTypedField('activity_kind', ChoiceType::class, ['label' => 'activity_log.filter.kind', 'choices' => $kinds, 'required' => false, 'placeholder' => 'activity_log.filter.any_kind']);
            $this->addTypedField('activity_person', ChoiceType::class, ['label' => 'activity_log.filter.person', 'choices' => $options['people'], 'required' => false, 'placeholder' => 'activity_log.filter.anybody', 'choice_translation_domain' => false]);
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
            'people'             => [],
            'allow_extra_fields' => true,
            'translation_domain' => TranslationDomain::NAME,
        ]);
    }

    /**
     * Field names `activity_kind`, `activity_person` (the record page's own query).
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
