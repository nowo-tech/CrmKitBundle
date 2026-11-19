<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\AffiliationType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ConsentType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HostLinkType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NoteType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagsAssignType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_values;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * The blocks of the 360° page (contract section 11.1): notes, tags, host links, affiliations, consent, vCard, GDPR
 * export and erasure. Every POST checks its CSRF token and redirects back to the record (303); a domain conflict or an
 * input error becomes a flash. Stateless.
 */
final readonly class RecordPartsController
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private NoteServiceInterface $notes,
        private TagServiceInterface $tags,
        private HostLinkServiceInterface $links,
        private RecordServiceInterface $records,
        private ConsentServiceInterface $consents,
        private CrmExporterInterface $exporter,
        private CrmPersonalDataInterface $personalData,
        private RecordForms $forms,
    ) {
    }

    /**
     * Adds a note.
     */
    #[AsWriteUnit]
    public function addNote(Request $request, string $type, string $id): Response
    {
        $subject = RecordController::type($type);

        return $this->ui->act($request, function () use ($request, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $data = $this->submitted($request, NoteType::class, 'crm_note');
            $this->notes->add($subject, $id, is_string($data['body'] ?? null) ? $data['body'] : '', ($data['pinned'] ?? false) === true); // @igor-ignore - a change of a per-call entity or record; nothing is kept on the service
            $this->ui->flash($request, 'success', 'note.added');

            return $this->ui->toRecord($subject, $id);
        }, $this->ui->toRecord($subject, $id));
    }

    /**
     * Edits a note (`?type=…&subject=…` names the page to go back to).
     */
    #[AsWriteUnit]
    public function editNote(Request $request, string $id): Response
    {
        $back = $this->back($request);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $data = $this->submitted($request, NoteType::class, 'crm_note', ['versioned' => true]);
            $this->notes->edit($id, is_string($data['body'] ?? null) ? $data['body'] : '', ($data['pinned'] ?? false) === true, is_numeric($data['version'] ?? null) ? (int) $data['version'] : -1);
            $this->ui->flash($request, 'success', 'note.saved');

            return $back;
        }, $back);
    }

    /**
     * Deletes a note.
     */
    #[AsWriteUnit]
    public function deleteNote(Request $request, string $id): Response
    {
        $back = $this->back($request);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->submitted($request, ActionType::class, 'crm_action');
            $this->notes->delete($id);
            $this->ui->flash($request, 'success', 'note.deleted');

            return $back;
        }, $back);
    }

    /**
     * Sets the tags of a record.
     */
    #[AsWriteUnit]
    public function assignTags(Request $request, string $type, string $id): Response
    {
        $subject = RecordController::type($type);

        return $this->ui->act($request, function () use ($request, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $tags = [];
            foreach ($this->forms->reader()->tags() as $tag) {
                $tags[$tag->name] = $tag->id;
            }
            $data = $this->submitted($request, TagsAssignType::class, 'crm_tags', ['tags' => $tags]);
            $ids  = is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], is_string(...))) : [];
            $this->tags->assign($subject, $id, $ids);
            $this->ui->flash($request, 'success', 'tags.saved');

            return $this->ui->toRecord($subject, $id);
        }, $this->ui->toRecord($subject, $id));
    }

    /**
     * Links a record to a host object.
     */
    #[AsWriteUnit]
    public function addLink(Request $request, string $type, string $id): Response
    {
        $subject = RecordController::type($type);

        return $this->ui->act($request, function () use ($request, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $types = $this->forms->hostTypes();
            $data  = $this->submitted($request, HostLinkType::class, 'crm_link', ['types' => $types]);
            try {
                $host = new HostObjectRef(is_string($data['hostType'] ?? null) ? $data['hostType'] : '', is_string($data['hostId'] ?? null) ? $data['hostId'] : '');
            } catch (InvalidArgumentException) {
                throw InvalidInputException::field('hostId', 'error.invalid');
            }
            $this->links->link($subject, $id, $host, is_string($data['label'] ?? null) ? $data['label'] : null);
            $this->ui->flash($request, 'success', 'link.added');

            return $this->ui->toRecord($subject, $id);
        }, $this->ui->toRecord($subject, $id));
    }

    /**
     * Removes a host link.
     */
    #[AsWriteUnit]
    public function removeLink(Request $request, string $id): Response
    {
        $back = $this->back($request);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->submitted($request, ActionType::class, 'crm_action');
            $this->links->unlink($id);
            $this->ui->flash($request, 'success', 'link.removed');

            return $back;
        }, $back);
    }

    /**
     * Relates a contact to a company.
     */
    #[AsWriteUnit]
    public function affiliate(Request $request, string $id): Response
    {
        $back = $this->ui->toRecord(SubjectType::Contact, $id);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $company = $request->request->all('crm_affiliation')['company'] ?? null;
            $data    = $this->submitted($request, AffiliationType::class, 'crm_affiliation', ['companies' => is_string($company) && RecordId::isValid($company) ? [$company => $company] : [], 'purposes' => $this->forms->affiliationPurposes()]);
            $this->records->affiliate(
                $id,
                is_string($data['company'] ?? null) ? $data['company'] : '',
                is_string($data['role'] ?? null) ? $data['role'] : null,
                ($data['primary'] ?? false) === true,
                ($data['primary_contact'] ?? false) === true,
                is_array($data['purposes'] ?? null) ? array_values(array_filter($data['purposes'], is_string(...))) : null,
            );
            $this->ui->flash($request, 'success', 'affiliation.added');

            return $back;
        }, $back);
    }

    /**
     * Makes a contact the company's primary contact (a POST with the action token).
     */
    #[AsWriteUnit]
    public function primaryContact(Request $request, string $id, string $contact): Response
    {
        $back = $this->ui->toRecord(SubjectType::Company, $id);

        return $this->ui->act($request, function () use ($request, $id, $contact, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->submitted($request, ActionType::class, 'crm_action');
            $this->records->setPrimaryContact($id, $contact); // @igor-ignore - a write through a stateless service; nothing is kept on the controller
            $this->ui->flash($request, 'success', 'affiliation.primary_contact_set');

            return $back;
        }, $back);
    }

    /**
     * Removes an affiliation.
     */
    #[AsWriteUnit]
    public function unaffiliate(Request $request, string $id): Response
    {
        $back = $this->back($request);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->submitted($request, ActionType::class, 'crm_action');
            $this->records->unaffiliate($id);
            $this->ui->flash($request, 'success', 'affiliation.removed');

            return $back;
        }, $back);
    }

    /**
     * Records a consent decision.
     */
    #[AsWriteUnit]
    public function consent(Request $request, string $id): Response
    {
        $back = $this->ui->toRecord(SubjectType::Contact, $id);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $data = $this->submitted($request, ConsentType::class, 'crm_consent', ['purposes' => $this->forms->purposes()]);
            $this->consents->record($id, is_string($data['purpose'] ?? null) ? $data['purpose'] : '', ($data['granted'] ?? null) === '1', ConsentSource::Manual, is_string($data['evidence'] ?? null) ? $data['evidence'] : null);
            $this->ui->flash($request, 'success', 'consent.recorded');

            return $back;
        }, $back);
    }

    /**
     * A contact's vCard.
     */
    public function vcard(string $id): Response
    {
        return $this->ui->guard(fn (): Response => new Response($this->exporter->vcard($id), Response::HTTP_OK, [
            'Content-Type'        => 'text/vcard; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="contact-' . $id . '.vcf"',
            'Cache-Control'       => 'no-store, private',
        ]));
    }

    /**
     * The GDPR export of a contact (JSON): a POST with the action token (`crm_action[_token]`), since it writes a
     * timeline entry and an audit entry.
     */
    #[AsWriteUnit]
    public function gdprExport(Request $request, string $id): Response
    {
        return $this->ui->act($request, function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->submitted($request, ActionType::class, 'crm_action');
            $response = new JsonResponse($this->personalData->export($id));
            $response->headers->set('Content-Disposition', 'attachment; filename="contact-' . $id . '.json"');
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }, $this->ui->toRecord(SubjectType::Contact, $id));
    }

    /**
     * Erases a contact's personal data.
     */
    #[AsWriteUnit]
    public function erase(Request $request, string $id): Response
    {
        $back = $this->ui->toRecord(SubjectType::Contact, $id);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->submitted($request, ActionType::class, 'crm_action');
            $this->personalData->erase($id);
            $this->ui->flash($request, 'success', 'record.erased');

            return $back;
        }, $back);
    }

    /**
     * The data of a submitted, valid form, or an input error.
     *
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function submitted(Request $request, string $type, string $name, array $options = []): array
    {
        /** @var FormInterface<mixed> $form */
        $form = $this->ui->form($type, null, $options, $name);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw InvalidInputException::field('_', $form->isSubmitted() && !$form->isValid() && $form->getErrors()->count() > 0 ? 'error.csrf' : 'error.invalid');
        }
        $data = $form->getData();

        /** @var array<string, mixed> $data */
        $data = is_array($data) ? $data : [];

        return $data;
    }

    /**
     * Where a block action returns: the record named by `?type=…&subject=…`, else the CRM home.
     */
    private function back(Request $request): RedirectResponse
    {
        $type    = SubjectType::fromSegment((string) $request->query->get('type', ''));
        $subject = (string) $request->query->get('subject', '');

        return $type instanceof SubjectType && RecordId::isValid($subject) ? $this->ui->toRecord($type, $subject) : $this->ui->redirect('nowo_crm_kit_home');
    }
}
