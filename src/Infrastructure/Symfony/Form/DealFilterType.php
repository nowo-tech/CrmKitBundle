<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The filters of the deal list (0.3.0, GET): funnel, status, text, stale only and the order.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
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
            // 0.10.2 (Beacon filter standard): the search first, named by its placeholder / aria-label; "Any funnel";
            // owner (Mine, No owner), stage, company, contact and the expected close range; the order is the sortable
            // column headers (no sort select); "Rows per page" with ui.list_per_page.
            $this->addTypedField('q', SearchType::class, ['label' => 'list.filter.q', 'required' => false, 'attr' => ['maxlength' => 100]]);
            $this->addTypedField('funnel', ChoiceType::class, ['label' => 'deal.funnel', 'choices' => $options['funnels'], 'required' => false, 'placeholder' => 'deal.filter.any_funnel', 'choice_translation_domain' => false]);
            $this->addTypedField('status', ChoiceType::class, ['label' => 'deal.status_label', 'choices' => ['deal.status.open' => 'open', 'deal.status.won' => 'won', 'deal.status.lost' => 'lost', 'deal.status.trashed' => 'trashed'], 'required' => false, 'placeholder' => 'deal.status.any']);
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'list.filter.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'list.filter.any_owner', 'choice_translation_domain' => false]);
            $this->addTypedField('stage', ChoiceType::class, ['label' => 'deal.form.stage', 'choices' => $options['stages'], 'required' => false, 'placeholder' => 'deal.filter.any_stage', 'choice_translation_domain' => false]);
            $this->addTypedField('company', ChoiceType::class, ['label' => 'deal.form.company', 'choices' => $options['companies'], 'required' => false, 'placeholder' => 'list.filter.any_company', 'choice_translation_domain' => false]);
            $this->addTypedField('contact', ChoiceType::class, ['label' => 'deal.form.contact', 'choices' => $options['contacts'], 'required' => false, 'placeholder' => 'deal.filter.any_contact', 'choice_translation_domain' => false]);
            $this->addTypedField('stale', CheckboxType::class, ['attr' => ['class' => ''], 'label' => 'deal.stale_only', 'required' => false]);
            $this->addTypedField('expected_close_from', DateType::class, FilterType::dateOptions('deal.filter.expected_close_from'));
            $this->addTypedField('expected_close_to', DateType::class, FilterType::dateOptions('deal.filter.expected_close_to'));
            /** @var list<int> $perPage */
            $perPage = $options['per_page'];
            if ($perPage !== []) {
                $this->addTypedField('per_page', ChoiceType::class, FilterType::perPageOptions($perPage));
            }
        });
    }

    /**
     * Array data, no CSRF (a GET filter).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => null,
            'csrf_protection' => false,
            'method'          => 'GET',
            'funnels'         => [],
            'owners'          => [],
            'stages'          => [],
            'companies'       => [],
            'contacts'        => [],
            'per_page'        => [],
            // Deprecated since 0.10.2 (the order is the sortable column headers): accepted and ignored.
            'sorts'              => [],
            'allow_extra_fields' => true,
            'translation_domain' => TranslationDomain::NAME,
        ]);
        $resolver->setAllowedTypes('per_page', 'int[]');
    }

    /**
     * Field names `q, funnel, status, owner, stage, company, contact, stale, expected_close_from, expected_close_to,
     * per_page`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
