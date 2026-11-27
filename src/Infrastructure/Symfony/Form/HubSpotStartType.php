<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

use function is_array;
use function is_string;

/**
 * What a HubSpot import reads (0.10.0): the objects, the lists (their names are HubSpot text, shown escaped and not
 * translated) and the duplicate policy; 0.13.0: the engagements (notes, calls, meetings, tasks) the configuration
 * imports.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class HubSpotStartType extends AbstractType
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
            $activities = is_array($options['activities']) ? $options['activities'] : [];
            // 0.13.0: with engagements on offer, an import may read engagements only (onto records of an earlier run).
            $this->addTypedField('objects', ChoiceType::class, ['label' => 'import.hubspot.form.objects', 'help' => 'import.hubspot.form.objects_help', 'multiple' => true, 'expanded' => true, 'required' => $activities === [], 'choices' => ['import.hubspot.object.companies' => 'companies', 'import.hubspot.object.contacts' => 'contacts'], 'constraints' => $activities === [] ? [new Count(min: 1)] : []]);
            if ($activities !== []) {
                $choices = [];
                foreach ($activities as $activity) {
                    $choices['import.hubspot.activity.' . (is_string($activity) ? $activity : '')] = is_string($activity) ? $activity : '';
                }
                $this->addTypedField('activities', ChoiceType::class, ['label' => 'import.hubspot.form.activities', 'help' => 'import.hubspot.form.activities_help', 'multiple' => true, 'expanded' => true, 'required' => false, 'choices' => $choices]);
            }
            if ($options['lists'] !== []) {
                $this->addTypedField('lists', ChoiceType::class, ['label' => 'import.hubspot.form.lists', 'help' => 'import.hubspot.form.lists_help', 'multiple' => true, 'expanded' => true, 'required' => false, 'choices' => $options['lists'], 'choice_translation_domain' => false, 'constraints' => [new Count(max: 50)]]);
            }
            $this->addTypedField('policy', ChoiceType::class, ['label' => 'import.form.policy', 'choices' => ['import.policy.skip' => 'skip', 'import.policy.update' => 'update']]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_hubspot_start`; `lists`: list name => list id; `activities` (0.13.0): the
     * engagement kinds on offer.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_hubspot_start',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'lists'              => [],
            'activities'         => [],
        ]);
        $resolver->setAllowedTypes('activities', 'string[]');
    }

    /**
     * Field names `crm_hubspot_start[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_hubspot_start';
    }
}
