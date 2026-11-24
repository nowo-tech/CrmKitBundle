<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;

/**
 * A company or contact: the core fields of its type ({@see RecordFields}), the owner, the party roles, the tags and one field per active
 * custom field (`custom_<key>`). The service validates; the form only shapes the input.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class RecordType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var SubjectType $type */
        $type = $options['subject_type'];
        /** @var list<FieldView> $fields */
        $fields = $options['fields'];
        $this->withBuilder($builder, function () use ($type, $fields, $options): void {
            foreach (RecordFields::of($type) as $name => [$max, $kind]) {
                $class = match ($kind) {
                    'textarea' => TextareaType::class,
                    'email'    => EmailType::class,
                    'url'      => UrlType::class,
                    'phone'    => TelType::class,
                    default    => TextType::class,
                };
                $extra = $kind === 'url' ? ['default_protocol' => null] : [];
                $this->addTypedField($name, $class, ['label' => 'field.' . $name, 'required' => $name === 'name', 'attr' => ['maxlength' => $max]] + $extra);
            }
            $this->addTypedField('owner', ChoiceType::class, ['label' => 'field.owner', 'choices' => $options['owners'], 'required' => false, 'placeholder' => 'field.owner_none', 'choice_translation_domain' => false]);
            if ($options['roles'] !== []) {
                $this->addTypedField('roles', ChoiceType::class, ['label' => 'field.roles', 'choices' => $options['roles'], 'multiple' => true, 'expanded' => true, 'required' => false]);
            }
            if ($options['tags'] !== []) {
                $this->addTypedField('tags', ChoiceType::class, ['label' => 'field.tags', 'choices' => $options['tags'], 'multiple' => true, 'expanded' => true, 'required' => false, 'choice_translation_domain' => false]);
            }
            foreach ($fields as $field) {
                $common          = ['label' => $field->label, 'translation_domain' => false, 'required' => $field->required && $field->type !== FieldType::Boolean];
                [$class, $extra] = match ($field->type) {
                    FieldType::Textarea => [TextareaType::class, ['attr' => ['maxlength' => 5000]]],
                    FieldType::Number   => [TextType::class, ['attr' => ['inputmode' => 'decimal', 'maxlength' => 30]]],
                    FieldType::Date     => [DateType::class, ['widget' => 'single_text', 'input' => 'string']],
                    FieldType::Boolean  => [CheckboxType::class, ['attr' => ['class' => '']]],
                    FieldType::Choice   => [ChoiceType::class, ['choices' => array_combine($field->choices, $field->choices), 'placeholder' => '', 'choice_translation_domain' => false]],
                    FieldType::Url      => [UrlType::class, ['default_protocol' => null, 'attr' => ['maxlength' => 255]]],
                    FieldType::Email    => [EmailType::class, ['attr' => ['maxlength' => 254]]],
                    FieldType::Text     => [TextType::class, ['attr' => ['maxlength' => 255]]],
                };
                $this->addTypedField('custom_' . $field->key, $class, $common + $extra);
            }
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_record`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_record',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'versioned'          => false,
            'owners'             => [],
            'tags'               => [],
            'roles'              => [],
            'fields'             => [],
        ]);
        $resolver->setRequired('subject_type');
        $resolver->setAllowedTypes('subject_type', SubjectType::class);
    }

    /**
     * Field names `crm_record[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_record';
    }
}
