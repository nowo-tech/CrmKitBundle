<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Application\PartyCatalogue;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectSearchInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectTypeLabelInterface;
use Nowo\CrmKitBundle\Domain\Read\FieldView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\CrmCatalogueInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\RecordType;
use Symfony\Component\Form\FormInterface;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function substr;

/**
 * Builds the record form from a view and turns its data into a {@see RecordInput}. Stateless.
 *
 * @internal
 */
final readonly class RecordForms
{
    /**
     * @param iterable<CrmHostObjectResolverInterface> $resolvers
     * @param list<string> $purposes consent purposes
     */
    public function __construct(private UiSupport $ui, private CrmReaderInterface $reader, private iterable $resolvers = [], private array $purposes = [], private CrmCatalogueInterface $catalogue = new PartyCatalogue())
    {
    }

    /**
     * Party roles (form and filter choices: label key `role.<key>` => key).
     *
     * @return array<string, string>
     */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->catalogue->roles() as $role) {
            $roles['role.' . $role] = $role;
        }

        return $roles;
    }

    /**
     * Affiliation purposes (form and filter choices: label key `purpose.<key>` => key).
     *
     * @return array<string, string>
     */
    public function affiliationPurposes(): array
    {
        $purposes = [];
        foreach ($this->catalogue->purposes() as $purpose) {
            $purposes['purpose.' . $purpose] = $purpose;
        }

        return $purposes;
    }

    /**
     * The reader.
     */
    public function reader(): CrmReaderInterface
    {
        return $this->reader;
    }

    /**
     * Host object types that have a resolver (link form choices: label => type). The label is the resolver's
     * {@see CrmHostObjectTypeLabelInterface::typeLabel()} (0.7.0), else `type()`; two types with one label keep
     * their type in brackets. Checked per call, nothing cached (worker mode).
     *
     * @return array<string, string>
     */
    public function hostTypes(): array
    {
        $types = [];
        foreach ($this->resolvers as $resolver) {
            $type = $resolver->type();
            if (in_array($type, $types, true)) {
                continue;
            }
            $label                                                               = $resolver instanceof CrmHostObjectTypeLabelInterface ? $this->ui->hostLabel($resolver->typeLabel()) : $type;
            $types[isset($types[$label]) ? $label . ' (' . $type . ')' : $label] = $type;
        }

        return $types;
    }

    /**
     * Whether a resolver offers the link picker (0.7.0, {@see CrmHostObjectSearchInterface}).
     */
    public function hasHostPicker(): bool
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver instanceof CrmHostObjectSearchInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Consent purposes (form choices).
     *
     * @return array<string, string>
     */
    public function purposes(): array
    {
        $purposes = [];
        foreach ($this->purposes as $purpose) {
            $purposes['consent.purpose.' . $purpose] = $purpose;
        }

        return $purposes;
    }

    /**
     * The form of a new record (`$view` null) or of an existing one.
     *
     * @return FormInterface<mixed>
     */
    public function form(SubjectType $type, ?RecordView $view): FormInterface
    {
        $fields = $this->reader->fields($type);
        $data   = [];
        if ($view instanceof RecordView) {
            $data = $view->values + ['owner' => $view->ownerId, 'tags' => array_map(static fn (TagView $t): string => $t->id, $view->tags), 'roles' => $view->roles, 'version' => (string) $view->version];
            foreach ($fields as $field) {
                $value                         = $view->custom[$field->key] ?? null;
                $data['custom_' . $field->key] = $field->type === FieldType::Boolean ? $value === '1' : $value;
            }
        }
        $owners = [];
        foreach ($this->reader->owners() as $owner) {
            $owners[$owner->name] = $owner->ref->value;
        }
        if ($view instanceof RecordView && $view->ownerId !== null && !in_array($view->ownerId, $owners, true)) {
            $owners[$view->ownerName ?? $view->ownerId] = $view->ownerId;
        }
        $tags = [];
        foreach ($this->reader->tags() as $tag) {
            $tags[$tag->name] = $tag->id;
        }

        return $this->ui->form(RecordType::class, $data, ['subject_type' => $type, 'fields' => $fields, 'owners' => $owners, 'tags' => $tags, 'roles' => $this->roles(), 'versioned' => $view instanceof RecordView], 'crm_record');
    }

    /**
     * The input of a submitted form.
     *
     * @param FormInterface<mixed> $form
     */
    public function input(SubjectType $type, FormInterface $form): RecordInput
    {
        $data   = $form->getData();
        $data   = is_array($data) ? $data : [];
        $values = [];
        foreach (RecordFields::names($type) as $name) {
            $values[$name] = is_scalar($data[$name] ?? null) ? (string) $data[$name] : null;
        }
        $custom = [];
        foreach ($this->reader->fields($type) as $field) {
            $raw                 = $data['custom_' . $field->key] ?? null;
            $custom[$field->key] = is_bool($raw) || is_string($raw) || $raw === null ? $raw : (is_scalar($raw) ? (string) $raw : null);
        }
        $tags  = is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], is_string(...))) : ($form->has('tags') ? [] : null);
        $roles = is_array($data['roles'] ?? null) ? array_values(array_filter($data['roles'], is_string(...))) : ($form->has('roles') ? [] : null);

        return new RecordInput($type, $values, is_string($data['owner'] ?? null) ? $data['owner'] : null, $custom, $tags, true, $roles);
    }

    /**
     * The custom field definitions of a type.
     *
     * @return list<FieldView>
     */
    public function fields(SubjectType $type): array
    {
        return $this->reader->fields($type);
    }

    /**
     * Label ⇒ id of the active records of a type the actor may see (at most 100; 0.10.2: also the company filter of
     * the contacts list).
     *
     * @return array<string, string>
     */
    public function records(SubjectType $type): array
    {
        $choices = [];
        foreach ($this->reader->list(new ListQuery($type, perPage: 100))->rows as $view) {
            $choices[$view->label . ' · ' . substr($view->id, -6)] = $view->id;
        }

        return $choices;
    }

    /**
     * The owners, by name.
     *
     * @return array<string, string>
     */
    public function owners(): array
    {
        $owners = [];
        foreach ($this->reader->owners() as $owner) {
            /* @var ActorProfile $owner */
            $owners[$owner->name] = $owner->ref->value;
        }

        return $owners;
    }
}
