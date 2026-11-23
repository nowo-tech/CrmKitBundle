<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

use function is_int;
use function max;

/**
 * An import upload: what, which format and the file (at most `max_bytes`: `import.max_bytes`).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class ImportUploadType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxBytes = $options['max_bytes'];
        $this->withBuilder($builder, function () use ($maxBytes): void {
            $this->addTypedField('subject', ChoiceType::class, ['label' => 'import.form.subject', 'choices' => ['import.subject.contacts' => 'contact', 'import.subject.companies' => 'company']]);
            $this->addTypedField('format', ChoiceType::class, ['label' => 'import.form.format', 'choices' => ['import.format.csv' => 'csv', 'import.format.vcard' => 'vcard']]);
            $this->addTypedField('file', FileType::class, ['label' => 'import.form.file', 'help' => 'import.form.file_help', 'constraints' => [new File(maxSize: is_int($maxBytes) ? max(1, $maxBytes) : 5242880)]]);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_import`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_import',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'max_bytes'          => 5242880,
        ]);
        $resolver->setAllowedTypes('max_bytes', 'int');
    }

    /**
     * Field names `crm_import[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_import';
    }
}
