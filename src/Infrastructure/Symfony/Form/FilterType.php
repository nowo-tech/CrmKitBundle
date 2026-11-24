<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            $this->addTypedField('q', SearchType::class, ['label' => 'list.filter.q', 'required' => false, 'attr' => ['maxlength' => 100]]);
            $this->addTypedField('tag', ChoiceType::class, ['label' => 'list.filter.tag', 'choices' => $options['tags'], 'required' => false, 'placeholder' => 'list.filter.any_tag', 'choice_translation_domain' => false]);
            if ($options['roles'] !== []) {
                $this->addTypedField('role', ChoiceType::class, ['label' => 'list.filter.role', 'choices' => $options['roles'], 'required' => false, 'placeholder' => 'list.filter.any_role']);
            }
            if ($options['purposes'] !== []) {
                $this->addTypedField('purpose', ChoiceType::class, ['label' => 'list.filter.purpose', 'choices' => $options['purposes'], 'required' => false, 'placeholder' => 'list.filter.any_purpose']);
                $this->addTypedField('primary_contact', ChoiceType::class, ['label' => 'list.filter.primary_contact', 'choices' => ['list.filter.primary_contact_only' => '1'], 'required' => false, 'placeholder' => 'list.filter.any_primary_contact']);
            }
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'list.filter.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'list.filter.any_owner', 'choice_translation_domain' => false]);
            $this->addTypedField('status', ChoiceType::class, ['label' => 'list.filter.status', 'choices' => ['list.status.active' => 'active', 'list.status.trashed' => 'trashed'], 'required' => false, 'placeholder' => false]);
            /** @var list<int> $perPage */
            $perPage = $options['per_page'];
            if ($perPage !== []) {
                $this->addTypedField('per_page', ChoiceType::class, self::perPageOptions($perPage));
            }
        });
    }

    /**
     * The options of the "Rows per page" select (0.8.0, `ui.list_per_page`): a visible label, the numbers as they are,
     * no empty choice. A value outside the choices is not an error of the filters: the list falls back to the page
     * size and the controller shows the value it used.
     *
     * @param list<int> $choices
     *
     * @return array<string, mixed>
     */
    public static function perPageOptions(array $choices): array
    {
        return ['label' => 'list.filter.per_page', 'choices' => array_combine(array_map(strval(...), $choices), $choices), 'required' => false, 'placeholder' => false, 'choice_translation_domain' => false];
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
            'per_page'           => [],
        ]);
        $resolver->setAllowedTypes('per_page', 'int[]');
    }

    /**
     * Field names `q, tag, role, purpose, primary_contact, owner, status, per_page[...]`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
