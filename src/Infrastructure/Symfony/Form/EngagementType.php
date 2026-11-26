<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\CrmKitBundle\Domain\Value\ActivityDuration;
use Nowo\CrmKitBundle\Domain\Value\EmailExcerpt;
use Nowo\CrmKitBundle\Domain\Value\MeetingPlace;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A logged call, meeting or e-mail (0.11.0). The times are local date-times (`Y-m-d\TH:i`), read in the meeting's time
 * zone (a meeting) or the application's (a call, an e-mail) by the controller; the domain validates every value.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class EngagementType extends AbstractType
{
    use FormOptionsTrait;

    /** The format of the local date-time fields. */
    public const LOCAL = 'Y-m-d\TH:i';

    /**
     * Adds the fields of the kind.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void {
            $kind       = $options['kind'];
            $time       = ['widget' => 'single_text', 'input' => 'string', 'input_format' => self::LOCAL, 'model_timezone' => 'UTC', 'view_timezone' => 'UTC', 'with_seconds' => false];
            $directions = ['activity_log.direction.' . ActivityDirection::Out->value => ActivityDirection::Out->value, 'activity_log.direction.' . ActivityDirection::In->value => ActivityDirection::In->value];
            if ($kind === 'meeting') {
                $this->addTypedField('title', TextType::class, ['label' => 'activity_log.form.meeting_title', 'attr' => ['maxlength' => 200]]);
                $this->addTypedField('occurred_at', DateTimeType::class, ['label' => 'activity_log.form.starts_at'] + $time);
                $this->addTypedField('ends_at', DateTimeType::class, ['label' => 'activity_log.form.ends_at'] + $time);
                $this->addTypedField('timezone', TimezoneType::class, ['label' => 'activity_log.form.timezone', 'choice_translation_domain' => false]);
                $this->addTypedField('place', TextType::class, ['label' => 'activity_log.form.place', 'required' => false, 'attr' => ['maxlength' => MeetingPlace::MAX]]);
                $this->addTypedField('members', ChoiceType::class, ['label' => 'activity_log.form.members', 'choices' => $options['members'], 'multiple' => true, 'required' => false, 'choice_translation_domain' => false]);
                if ($options['contacts'] !== []) {
                    $this->addTypedField('contacts', ChoiceType::class, ['label' => 'activity_log.form.contacts', 'choices' => $options['contacts'], 'multiple' => true, 'expanded' => true, 'required' => false, 'choice_translation_domain' => false]);
                }
                $this->addTypedField('body', TextareaType::class, ['label' => 'activity_log.form.notes', 'required' => false, 'attr' => ['maxlength' => 10000, 'rows' => 4]]);
                if ($options['versioned'] === true) {
                    $this->addTypedField('cancelled', CheckboxType::class, ['label' => 'activity_log.form.cancelled', 'required' => false, 'attr' => ['class' => '']]);
                }
            } else {
                $this->addTypedField('occurred_at', DateTimeType::class, ['label' => 'activity_log.form.occurred_at'] + $time);
                $this->addTypedField('direction', ChoiceType::class, ['label' => 'activity_log.form.direction', 'choices' => $directions, 'expanded' => true]);
                if ($kind === 'call') {
                    $this->addTypedField('outcome', ChoiceType::class, ['label' => 'activity_log.form.outcome', 'choices' => $options['outcomes'], 'required' => false, 'placeholder' => 'activity_log.form.no_outcome']);
                    $this->addTypedField('duration', IntegerType::class, ['label' => 'activity_log.form.duration', 'required' => false, 'attr' => ['min' => 0, 'max' => ActivityDuration::MAX]]);
                    $this->addTypedField('title', TextType::class, ['label' => 'activity_log.form.call_title', 'required' => false, 'attr' => ['maxlength' => 200]]);
                    $this->addTypedField('body', TextareaType::class, ['label' => 'activity_log.form.notes', 'required' => false, 'attr' => ['maxlength' => 10000, 'rows' => 4]]);
                } else {
                    $this->addTypedField('title', TextType::class, ['label' => 'activity_log.form.subject', 'attr' => ['maxlength' => 200]]);
                    $this->addTypedField('body', TextareaType::class, ['label' => 'activity_log.form.excerpt', 'required' => false, 'attr' => ['maxlength' => EmailExcerpt::MAX, 'rows' => 4]]);
                }
            }
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_activity`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_activity',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'kind'               => 'call',
            'outcomes'           => [],
            'members'            => [],
            'contacts'           => [],
            'versioned'          => false,
        ]);
        $resolver->setAllowedValues('kind', ['call', 'meeting', 'email']);
    }

    /**
     * Field names `crm_activity[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_activity';
    }
}
