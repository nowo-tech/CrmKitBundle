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
 * The filters of Tags and Custom fields (0.8.0, GET, no CSRF): the name search `q` and, with `ui.list_per_page`, the
 * "Rows per page" select.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class AdminFilterType extends AbstractType
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
            $this->addTypedField('q', SearchType::class, ['label' => 'list.filter.name', 'required' => false, 'attr' => ['maxlength' => 100]]);
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
            'data_class'         => null,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'method'             => 'GET',
            'per_page'           => [],
        ]);
        $resolver->setAllowedTypes('per_page', 'int[]');
    }

    /**
     * Field names `q, per_page`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
