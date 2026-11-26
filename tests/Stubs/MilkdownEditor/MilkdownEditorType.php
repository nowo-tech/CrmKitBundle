<?php

declare(strict_types=1);

// A stand-in of nowo-tech/milkdown-editor-bundle's form type (1.19+), loaded by tests/bootstrap.php only when
// MilkdownEditor is not installed: a text area that accepts the options the CRM passes (`suggestions`,
// `mention_display`, `user_title_url`) and prints them as data attributes, so the tests see what reached the field.

namespace Nowo\MilkdownEditorBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function json_encode;

/**
 * @extends AbstractType<mixed>
 */
final class MilkdownEditorType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['attr']['data-suggestions']     = (string) json_encode($options['suggestions']);
        $view->vars['attr']['data-mention-display'] = (string) $options['mention_display'];
        $view->vars['attr']['data-user-title-url']  = (string) $options['user_title_url'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['suggestions' => [], 'mention_display' => 'handle', 'user_title_url' => null]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}
