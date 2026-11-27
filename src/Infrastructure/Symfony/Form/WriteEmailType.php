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
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * *Write e-mail* to a contact (0.13.0): the template and snippet pickers with their "Insert" buttons (the server
 * renders the chosen row for the contact and fills the subject / appends to the text; nothing is evaluated in the
 * browser), a hidden default button (Enter in a field shows the form again: it never sends), an optional deal for `{{ deal.* }}`, the subject, the text, the inserted template (its consent purpose
 * applies to the send) and the one-time send key. Send is the dialog's primary action.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig(FormProfile::NAME)]
final class WriteEmailType extends AbstractType
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
            // The form's default button (Enter in a field): shows the form again, never sends or inserts.
            $this->addTypedField('stay', SubmitType::class, ['label' => 'mail.form.stay']);
            if ($options['templates'] !== []) {
                $this->addTypedField('template', ChoiceType::class, ['label' => 'mail.form.template', 'choices' => $options['templates'], 'required' => false, 'placeholder' => 'mail.form.no_template', 'choice_translation_domain' => false]);
                $this->addTypedField('insert_template', SubmitType::class, ['label' => 'mail.form.insert_template']);
            }
            if ($options['deals'] !== []) {
                $this->addTypedField('deal', ChoiceType::class, ['label' => 'mail.form.deal', 'help' => 'mail.form.deal_help', 'choices' => $options['deals'], 'required' => false, 'placeholder' => 'mail.form.no_deal', 'choice_translation_domain' => false]);
            }
            $this->addTypedField('subject', TextType::class, ['label' => 'mail.form.subject', 'attr' => ['maxlength' => CrmOutgoingMail::MAX_SUBJECT]]);
            $this->addTypedField('text', TextareaType::class, ['label' => 'mail.form.text', 'attr' => ['rows' => 12, 'maxlength' => CrmOutgoingMail::MAX_TEXT]]);
            if ($options['snippets'] !== []) {
                $this->addTypedField('snippet', ChoiceType::class, ['label' => 'mail.form.snippet', 'choices' => $options['snippets'], 'required' => false, 'placeholder' => 'mail.form.no_snippet', 'choice_translation_domain' => false]);
                $this->addTypedField('insert_snippet', SubmitType::class, ['label' => 'mail.form.insert_snippet']);
            }
            $this->addTypedField('applied', HiddenType::class, []);
            $this->addTypedField('send_key', HiddenType::class, []);
        });
    }

    /**
     * Array data, CSRF token `nowo_crm_kit_mail`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_crm_kit_mail',
            'allow_extra_fields' => false,
            'translation_domain' => TranslationDomain::NAME,
            'templates'          => [],
            'snippets'           => [],
            'deals'              => [],
        ]);
    }

    /**
     * Field names `crm_mail[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'crm_mail';
    }
}
