<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Form;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_array;

/**
 * An e-mail template or snippet (0.13.0): key, name, locale, scope (new rows only, `manage` for the organisation's),
 * a template's subject and consent classification, and the text body with its placeholders (`{{ contact.first_name }}`
 * …, listed in the help). The domain and MailKit validate every value.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class EmailTemplateType extends AbstractType
{
    use FormOptionsTrait;
    /** The classification of a service message (no consent purpose). */
    public const SERVICE = 'service';

    /**
     * Adds the fields of the kind.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void {
            $template = $options['kind'] === 'template';
            $this->addTypedField('name', TextType::class, ['label' => 'template.form.name', 'attr' => ['maxlength' => 190]]);
            $this->addTypedField('key', TextType::class, ['label' => 'template.form.key', 'help' => 'template.form.key_help', 'attr' => ['maxlength' => 64, 'pattern' => '[a-z0-9][a-z0-9._\-]{0,63}', 'autocapitalize' => 'off', 'spellcheck' => 'false']]);
            $this->addTypedField('locale', TextType::class, ['label' => 'template.form.locale', 'help' => 'template.form.locale_help', 'attr' => ['maxlength' => 6, 'pattern' => '[a-z]{2,3}(_[A-Z]{2})?', 'autocapitalize' => 'off', 'spellcheck' => 'false']]);
            if ($options['scopes'] !== []) {
                $this->addTypedField('shared', ChoiceType::class, ['label' => 'template.form.scope', 'choices' => $options['scopes'], 'expanded' => true]);
            }
            if ($template) {
                $this->addTypedField('subject', TextType::class, ['label' => 'template.form.subject', 'attr' => ['maxlength' => CrmOutgoingMail::MAX_SUBJECT]]);
                $purposes = is_array($options['purposes']) ? $options['purposes'] : [];
                $this->addTypedField('purpose', ChoiceType::class, ['label' => 'template.form.purpose', 'help' => 'template.form.purpose_help', 'choices' => ['template.form.service' => self::SERVICE] + $purposes]);
            }
            $this->addTypedField('text', TextareaType::class, ['label' => 'template.form.text', 'help' => 'template.form.text_help', 'help_translation_parameters' => ['%placeholders%' => $options['placeholders']], 'attr' => ['rows' => 10, 'maxlength' => CrmOutgoingMail::MAX_TEXT]]);
            if ($options['versioned'] === true) {
                $this->addTypedField('version', HiddenType::class, []);
            }
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_template`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_template',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'kind'               => 'template',
            'scopes'             => [],
            'purposes'           => [],
            'placeholders'       => '',
            'versioned'          => false,
        ]);
        $resolver->setAllowedValues('kind', ['template', 'snippet']);
    }

    /**
     * Field names `crm_template[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_template';
    }
}
