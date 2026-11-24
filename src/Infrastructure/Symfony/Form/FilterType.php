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
use Symfony\Component\Translation\TranslatableMessage;

use function array_combine;
use function array_map;

/**
 * The filters of a list (GET, no CSRF).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
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
            // 0.10.2: the search names itself through its placeholder and aria-label (no visible label in a filter bar).
            $this->addTypedField('q', SearchType::class, ['label' => 'list.filter.q', 'required' => false, 'attr' => ['maxlength' => 100]]);
            $this->addTypedField('tag', ChoiceType::class, ['label' => 'list.filter.tag', 'choices' => $options['tags'], 'required' => false, 'placeholder' => 'list.filter.any_tag', 'choice_translation_domain' => false]);
            if ($options['roles'] !== []) {
                $this->addTypedField('role', ChoiceType::class, ['label' => 'list.filter.role', 'choices' => $options['roles'], 'required' => false, 'placeholder' => 'list.filter.any_role']);
            }
            if ($options['purposes'] !== []) {
                $this->addTypedField('purpose', ChoiceType::class, ['label' => 'list.filter.purpose', 'choices' => $options['purposes'], 'required' => false, 'placeholder' => 'list.filter.any_purpose']);
                // 0.10.2: a checkbox instead of a three-state select whose "Any …" placeholder did not fit the field.
                $this->addTypedField('primary_contact', CheckboxType::class, ['label' => 'list.filter.primary_contacts_only', 'required' => false, 'value' => '1']);
            }
            if ($options['companies'] !== null) {
                // 0.10.2: contacts of one company (ListQuery::$companyId).
                $this->addTypedField('company', ChoiceType::class, ['label' => 'list.filter.company', 'choices' => $options['companies'], 'required' => false, 'placeholder' => 'list.filter.any_company', 'choice_translation_domain' => false]);
            }
            // 0.10.2: "Mine" and "No owner" lead the owner choices (labels translated by the caller, like the names).
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'list.filter.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'list.filter.any_owner', 'choice_translation_domain' => false]);
            $this->addTypedField('status', ChoiceType::class, ['label' => 'list.filter.status', 'choices' => ['list.status.active' => 'active', 'list.status.trashed' => 'trashed'], 'required' => false, 'placeholder' => false]);
            // 0.10.2: created / last changed day ranges (native date fields, From / To).
            foreach (['created_from', 'created_to', 'updated_from', 'updated_to'] as $date) {
                $this->addTypedField($date, DateType::class, self::dateOptions('list.filter.' . $date));
            }
            /** @var list<int> $perPage */
            $perPage = $options['per_page'];
            if ($perPage !== []) {
                $this->addTypedField('per_page', ChoiceType::class, self::perPageOptions($perPage));
            }
        });
    }

    /**
     * The options of the "Rows per page" select (0.8.0, `ui.list_per_page`): no empty choice. A value outside the
     * choices is not an error of the filters: the list falls back to the page size and the controller shows the value
     * it used.
     *
     * @param list<int> $choices
     *
     * @return array<string, mixed>
     */
    public static function perPageOptions(array $choices): array
    {
        // 0.10.2: compact ("25 per page"), named "Results per page" for assistive tech (the label is visually hidden).
        return [
            'label'        => 'list.filter.results_per_page',
            'label_attr'   => ['class' => 'visually-hidden'],
            'choices'      => array_combine(array_map(strval(...), $choices), $choices),
            'choice_label' => static fn (int|string $value): TranslatableMessage => new TranslatableMessage('list.filter.per_page_option', ['%count%' => (int) $value], TranslationDomain::NAME),
            'required'     => false,
            'placeholder'  => false,
        ];
    }

    /**
     * A day of a range filter (0.10.2): a native date field, optional.
     *
     * @return array<string, mixed>
     */
    public static function dateOptions(string $label): array
    {
        return ['label' => $label, 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'];
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
            'translation_domain' => TranslationDomain::NAME,
            'method'             => 'GET',
            'tags'               => [],
            'owners'             => [],
            'roles'              => [],
            'purposes'           => [],
            'companies'          => null,
            'per_page'           => [],
        ]);
        $resolver->setAllowedTypes('per_page', 'int[]');
        $resolver->setAllowedTypes('companies', ['null', 'array']);
    }

    /**
     * Field names `q, tag, role, purpose, primary_contact, company, owner, status, created_from, created_to,
     * updated_from, updated_to, per_page`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
