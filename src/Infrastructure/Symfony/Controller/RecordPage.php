<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\AffiliationType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ConsentType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HostLinkType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NoteType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagsAssignType;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\PageStatus;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function substr;

/**
 * The 360° page of a record (contract section 9): header with its actions, details (core and custom fields), contacts
 * or companies, host links, host panels, notes, timeline, consent and possible duplicates; the forms each block posts.
 * Stateless.
 *
 * @internal
 */
final readonly class RecordPage
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private UiSupport $ui, private CrmReaderInterface $reader, private CrmAccess $access, private RecordForms $forms)
    {
    }

    /**
     * Renders the page.
     */
    public function render(RecordView $view): Response
    {
        $type    = $view->type;
        $seg     = $type->segment();
        $subject = SubjectRef::of($type, $view->id, $view->ownerId);
        $active  = $view->status === RecordStatus::Active;
        $edit    = $active && $this->access->can(CrmPermission::Edit, $subject);
        $manage  = $this->access->can(CrmPermission::Manage, $subject);
        $token   = $this->ui->actionToken();
        $actions = [];
        if ($edit) {
            $actions[] = Action::edit($this->ui->url('nowo_crm_kit_edit', ['type' => $seg, 'id' => $view->id]), UiSupport::label('record.edit'));
            $actions[] = new Action('custom', UiSupport::label('record.merge'), $this->ui->url('nowo_crm_kit_merge', ['type' => $seg, 'id' => $view->id]), icon: 'merge');
        }
        if ($type === SubjectType::Contact && $view->status !== RecordStatus::Erased) {
            $actions[] = new Action('download', UiSupport::label('record.vcard'), $this->ui->url('nowo_crm_kit_vcard', ['id' => $view->id]), icon: 'download');
        }
        if ($type === SubjectType::Contact && $manage) {
            // A POST with the action token: the export is a logged processing of personal data, never a plain link.
            $actions[] = $this->post($token, 'download', 'record.gdpr_export', 'nowo_crm_kit_gdpr_export', ['id' => $view->id]);
        }
        $back = ['type' => $seg, 'subject' => $view->id];
        if ($edit) {
            $actions[] = $this->post($token, 'delete', 'record.trash', 'nowo_crm_kit_trash', ['type' => $seg, 'id' => $view->id], 'record.trash_confirm', 'danger');
        }
        if ($view->status === RecordStatus::Trashed && $this->access->can(CrmPermission::Edit, $subject)) {
            $actions[] = $this->post($token, 'restore', 'record.restore', 'nowo_crm_kit_restore', ['type' => $seg, 'id' => $view->id]);
        }
        if ($view->status === RecordStatus::Trashed && $manage) {
            $actions[] = $this->post($token, 'delete', 'record.purge', 'nowo_crm_kit_purge', ['type' => $seg, 'id' => $view->id], 'record.purge_confirm', 'danger');
        }
        if ($type === SubjectType::Contact && $manage && $view->status !== RecordStatus::Erased) {
            $actions[] = $this->post($token, 'delete', 'record.erase', 'nowo_crm_kit_erase', ['id' => $view->id], 'record.erase_confirm', 'danger');
        }
        $details = [];
        foreach (RecordFields::names($type) as $field) {
            $value = $view->values[$field] ?? null;
            if ($value !== null) {
                $details[] = new DetailItem(UiSupport::label('field.' . $field), $value, $field === 'website' ? $value : ($field === 'email' ? 'mailto:' . $value : null));
            }
        }
        $custom = [];
        foreach ($this->reader->fields($type) as $field) {
            $value = $view->custom[$field->key] ?? null;
            if ($value !== null) {
                $custom[] = new DetailItem($field->label, $field->type === FieldType::Boolean ? $this->ui->trans($value === '1' ? 'value.yes' : 'value.no') : $value);
            }
        }
        $details[] = new DetailItem(UiSupport::label('field.owner'), $view->ownerName ?? $view->ownerId ?? $this->ui->trans('field.owner_none'));
        $sections  = [new DetailSection(UiSupport::label('record.details'), $details, 'crm-details')];
        if ($custom !== []) {
            $sections[] = new DetailSection(UiSupport::label('record.custom'), $custom, 'crm-custom');
        }
        $status = match ($view->status) {
            RecordStatus::Trashed => new PageStatus(UiSupport::label('status.trashed'), 'warning'),
            RecordStatus::Erased  => new PageStatus(UiSupport::label('status.erased'), 'neutral'),
            default               => null,
        };
        $forms = [];
        if ($edit) {
            $forms['note']  = $this->ui->form(NoteType::class, null, [], 'crm_note')->createView();
            $forms['notes'] = [];
            $tags           = [];
            foreach ($this->reader->tags() as $tag) {
                $tags[$tag->name] = $tag->id;
            }
            if ($tags !== []) {
                $forms['tags'] = $this->ui->form(TagsAssignType::class, ['tags' => array_map(static fn (TagView $t): string => $t->id, $view->tags)], ['tags' => $tags], 'crm_tags')->createView();
            }
            $types = $this->forms->hostTypes();
            if ($types !== []) {
                $forms['link'] = $this->ui->form(HostLinkType::class, null, ['types' => $types], 'crm_link')->createView();
            }
            if ($type === SubjectType::Contact) {
                $companies = [];
                foreach ($this->reader->list(new ListQuery(SubjectType::Company, perPage: 100))->rows as $company) {
                    $companies[$company->label . ' · ' . substr($company->id, -6)] = $company->id;
                }
                if ($companies !== []) {
                    $forms['affiliation'] = $this->ui->form(AffiliationType::class, null, ['companies' => $companies, 'purposes' => $this->forms->affiliationPurposes()], 'crm_affiliation')->createView();
                }
                $purposes = $this->forms->purposes();
                if ($purposes !== []) {
                    $forms['consent'] = $this->ui->form(ConsentType::class, null, ['purposes' => $purposes], 'crm_consent')->createView();
                }
            }
        }

        $notes   = $this->reader->notes($type, $view->id);
        $links   = $this->reader->links($type, $view->id);
        $buttons = ['notes' => [], 'links' => [], 'affiliations' => [], 'primary_contact' => []];
        if ($edit) {
            foreach ($notes as $note) {
                $forms['notes'][$note->id]   = $this->ui->form(NoteType::class, ['body' => $note->body, 'pinned' => $note->pinned, 'version' => (string) $note->version], ['versioned' => true], 'crm_note')->createView();
                $buttons['notes'][$note->id] = $this->post($token, 'delete', 'note.delete', 'nowo_crm_kit_note_delete', ['id' => $note->id] + $back, 'note.delete_confirm', 'danger');
            }
            foreach ($links as $link) {
                $buttons['links'][$link->id] = $this->post($token, 'delete', 'link.remove', 'nowo_crm_kit_link_remove', ['id' => $link->id] + $back);
            }
            foreach ($view->affiliations as $affiliation) {
                $buttons['affiliations'][$affiliation->id] = $this->post($token, 'delete', 'affiliation.remove', 'nowo_crm_kit_unaffiliate', ['id' => $affiliation->id] + $back);
                if ($type === SubjectType::Company && !$affiliation->primaryContact) {
                    $buttons['primary_contact'][$affiliation->id] = $this->post($token, 'custom', 'affiliation.make_primary_contact', 'nowo_crm_kit_primary_contact', ['id' => $view->id, 'contact' => $affiliation->contactId]);
                }
            }
        }

        return $this->ui->render('@NowoCrmKit/record/show.html.twig', [
            'page' => new ShowPage(
                new PageHeader($view->label, UiSupport::label('type.' . $type->value), $actions, tabs: $this->ui->tabs($seg), status: $status),
                $sections,
                bundle: UiSupport::DOMAIN,
                layout: $this->ui->layout(),
                attributes: ['data-testid' => 'crm-record', 'data-crm-type' => $type->value],
            ),
            'record'     => $view,
            'segment'    => $seg,
            'can_edit'   => $edit,
            'can_manage' => $manage,
            'forms'      => $forms,
            'notes'      => $notes,
            'timeline'   => $this->reader->timeline($type, $view->id, 30),
            'links'      => $links,
            'buttons'    => $buttons,
            'back'       => $back,
            'panels'     => $active ? $this->reader->panels($type, $view->id) : [],
            'duplicates' => $active ? $this->reader->duplicates($type, $view->id) : [],
            'consents'   => $type === SubjectType::Contact ? $this->reader->consents($view->id) : [],
        ]);
    }

    /**
     * A POST action of the page (CSRF token of `crm_action`).
     *
     * @param array<string, string> $params
     */
    private function post(string $token, string $kind, string $label, string $route, array $params, ?string $confirm = null, ?string $variant = null): Action
    {
        return new Action(
            $kind,
            UiSupport::label($label),
            $this->ui->url($route, $params),
            method: 'POST',
            csrfToken: $token,
            confirm: $confirm === null ? null : new Confirm(UiSupport::label($confirm), null, UiSupport::label($label)),
            variant: $variant,
            csrfField: 'crm_action[_token]',
        );
    }
}
