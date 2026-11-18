<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The column mapping and the duplicate policy of an import.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class ImportConfirmType extends AbstractType
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
            /** @var list<string> $list */
            $list = $options['columns'];
            foreach ($list as $i => $column) {
                $this->addTypedField('col_' . $i, ChoiceType::class, ['label' => $column, 'translation_domain' => false, 'choices' => $options['targets'], 'choice_translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN, 'required' => false, 'placeholder' => false]);
            }
            $this->addTypedField('policy', ChoiceType::class, ['label' => 'import.form.policy', 'choices' => ['import.policy.skip' => 'skip', 'import.policy.update' => 'update']]);
            $this->addTypedField('version', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_import_confirm`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_import_confirm',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'columns'            => [],
            'targets'            => [],
        ]);
    }

    /**
     * Field names `crm_import_confirm[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_import_confirm';
    }
}
