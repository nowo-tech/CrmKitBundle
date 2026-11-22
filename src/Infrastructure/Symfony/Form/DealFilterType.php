<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The filters of the deal list (0.3.0, GET): funnel, status, text, stale only and the order.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class DealFilterType extends AbstractType
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
            $this->addTypedField('funnel', ChoiceType::class, ['label' => 'deal.funnel', 'choices' => $options['funnels'], 'required' => false, 'placeholder' => 'list.filter.any', 'choice_translation_domain' => false]);
            $this->addTypedField('status', ChoiceType::class, ['label' => 'deal.status_label', 'choices' => ['deal.status.open' => 'open', 'deal.status.won' => 'won', 'deal.status.lost' => 'lost', 'deal.status.trashed' => 'trashed'], 'required' => false, 'placeholder' => 'deal.status.any']);
            $this->addTypedField('q', SearchType::class, ['label' => 'list.filter.q', 'required' => false, 'attr' => ['maxlength' => 100]]);
            $this->addTypedField('stale', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'deal.stale_only', 'required' => false]);
            $this->addTypedField('sort', ChoiceType::class, ['label' => 'deal.sort_label', 'choices' => $options['sorts'], 'required' => false, 'placeholder' => false]);
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
            'funnels'            => [],
            'sorts'              => ['deal.sort.updated' => 'updated', 'deal.sort.expected_close' => 'expected_close'],
            'allow_extra_fields' => true,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Field names `funnel, status, q, stale, sort`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
