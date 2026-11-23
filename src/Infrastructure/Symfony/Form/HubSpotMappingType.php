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
 * The mapping step of a HubSpot import (0.10.0): one select per HubSpot property and per list (labels and choices
 * already translated by the controller; HubSpot's labels are shown escaped), the duplicate policy and the version.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(NowoCrmKitBundle::FORM_PROFILE)]
final class HubSpotMappingType extends AbstractType
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
            /** @var list<array{name: string, label: string, choices: array<string, string>}> $rows */
            $rows = $options['rows'];
            foreach ($rows as $row) {
                $this->addTypedField($row['name'], ChoiceType::class, ['label' => $row['label'], 'translation_domain' => false, 'choices' => $row['choices'], 'choice_translation_domain' => false, 'required' => false, 'placeholder' => false]);
            }
            $this->addTypedField('policy', ChoiceType::class, ['label' => 'import.form.policy', 'choices' => ['import.policy.skip' => 'skip', 'import.policy.update' => 'update']]);
            $this->addTypedField('version', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_hubspot_mapping`; `rows`: the selects.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_hubspot_mapping',
            'allow_extra_fields' => false,
            'translation_domain' => NowoCrmKitBundle::TRANSLATION_DOMAIN,
            'rows'               => [],
        ]);
    }

    /**
     * Field names `crm_hubspot_mapping[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_hubspot_mapping';
    }
}
