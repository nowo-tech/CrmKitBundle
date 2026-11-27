<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Engagement\Mentions;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FeedKind;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Read\EngagementView;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\ActivityFeedInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineFilter;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FeedFilterType;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Confirm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function class_exists;
use function in_array;
use function is_string;

/**
 * The activity block of the 360° page of a company or contact (0.11.0, `activities.enabled`): the feed (notes, logged
 * calls, meetings and e-mails, host items) with its kind and person filter and its "Show older" page, the openers of
 * the log dialogs (forms loaded on demand), each activity's edit / delete / `.ics` actions, the pin buttons of the
 * notes card, the notes' text with mentions shown as names, and the MilkdownEditor note field with the mention
 * candidates (when MilkdownEditor is installed and `activities.mentions.enabled`). Stateless.
 *
 * @internal
 */
final readonly class ActivityRecordBlock
{
    /** MilkdownEditor's form type (a literal: MilkdownEditor is optional). */
    public const MILKDOWN_TYPE = 'Nowo\\MilkdownEditorBundle\\Form\\MilkdownEditorType';

    /** Entries per feed page. */
    public const PAGE = 20;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private ActivityFeedInterface $feed,
        private CrmAccess $access,
        private CrmReaderInterface $reader,
        private PipelineDialogs $dialogs,
        private RequestStack $requests,
        private ?Mentions $mentions = null,
        private bool $milkdown = false,
    ) {
    }

    /**
     * The block's variables (`$edit`: the actor may edit the record).
     *
     * @param list<NoteView> $notes the notes card's notes
     *
     * @return array<string, mixed>
     */
    public function variables(RecordView $view, bool $edit, array $notes): array
    {
        $request = $this->requests->getCurrentRequest() ?? new Request();
        $seg     = $view->type->segment();
        $people  = [];
        foreach ($this->reader->owners() as $profile) {
            $people[$profile->name] = $profile->ref->value;
        }
        $kind   = FeedKind::tryFrom((string) $request->query->get('activity_kind', ''));
        $person = (string) $request->query->get('activity_person', '');
        $person = in_array($person, $people, true) ? $person : null;
        $filter = new TimelineFilter($kind instanceof FeedKind ? [$kind] : [], $person === null ? null : new ActorRef($person));
        $before = $request->query->get('activity_before');
        $feed   = $this->feed->feed($view->type, $view->id, $filter, TimelineWindow::after(is_string($before) ? $before : null, self::PAGE));
        $form   = $this->ui->form(FeedFilterType::class, ['activity_kind' => $kind?->value, 'activity_person' => $person], ['people' => $people], '');
        $actor  = $this->access->base()->actor->value;
        $manage = $this->access->can(CrmPermission::Manage, SubjectRef::of($view->type, $view->id, $view->ownerId));
        $token  = $this->ui->actionToken();
        $back   = ['type' => $seg, 'subject' => $view->id];
        $active = $view->status === RecordStatus::Active;
        $items  = [];
        foreach ($feed->items as $item) {
            $buttons = [];
            if ($item->engagement instanceof EngagementView) {
                // Audit ZCR-I7: an activity of another record (fan-out) is changed on its own record's page.
                $own     = $item->engagement->subjectLabel === null;
                $buttons = $this->engagementButtons($item->engagement, $own && $edit && $active && ($item->engagement->authorId === $actor || $manage), $token, $back);
            }
            $items[] = ['item' => $item, 'buttons' => $buttons];
        }
        $openers = [];
        if ($edit && $active) {
            foreach (ActivityKind::LOGGABLE as $loggable) {
                $icon = match ($loggable) {
                    ActivityKind::Call    => UiIcons::CALL,
                    ActivityKind::Meeting => UiIcons::MEETING,
                    default               => UiIcons::EMAIL,
                };
                $opener = $this->dialogs->enabled()
                    ? $this->dialogs->opener('custom', 'activity_log.log_' . $loggable->value, 'nowo_crm_kit_activity_log', ['type' => $seg, 'id' => $view->id, 'kind' => $loggable->value], 'crm-activity-log-' . $loggable->value, 'secondary')
                    : new Action('custom', UiSupport::label('activity_log.log_' . $loggable->value), $this->ui->url('nowo_crm_kit_activity_log', ['type' => $seg, 'id' => $view->id, 'kind' => $loggable->value]), variant: 'secondary', attributes: ['data-testid' => 'crm-activity-log-' . $loggable->value]);
                $openers[] = $opener->withDisplay('icon_text', $icon);
            }
        }
        $pins = [];
        if ($edit && $active) {
            foreach ($notes as $note) {
                $pins[$note->id] = new Action('custom', UiSupport::label($note->pinned ? 'activity_log.unpin' : 'activity_log.pin'), $this->ui->url('nowo_crm_kit_note_pin', ['id' => $note->id, 'state' => $note->pinned ? 'off' : 'on', 'version' => (string) $note->version] + $back), method: 'POST', csrfToken: $token, icon: $note->pinned ? UiIcons::UNPIN : UiIcons::PIN, variant: 'secondary', attributes: ['data-testid' => 'crm-note-pin'], csrfField: 'crm_action[_token]');
            }
        }
        $query = [];
        foreach (['activity_kind' => $kind?->value, 'activity_person' => $person] as $name => $value) {
            if ($value !== null) {
                $query[$name] = $value;
            }
        }

        return [
            'items'    => $items,
            'filter'   => $form->createView(),
            'filtered' => $query !== [],
            'clear'    => $this->ui->url('nowo_crm_kit_show', ['type' => $seg, 'id' => $view->id]) . '#crm-activities',
            'older'    => $feed->next === null ? null : $this->ui->url('nowo_crm_kit_show', ['type' => $seg, 'id' => $view->id] + $query + ['activity_before' => $feed->next]) . '#crm-activities',
            'openers'  => $openers,
            'pins'     => $pins,
            'texts'    => $this->texts($notes),
            'dialog'   => $openers === [] ? null : $this->dialogs->shell('activity_log.title'),
        ];
    }

    /**
     * The MilkdownEditor note field with the mention candidates of the record, or null (a plain text area).
     *
     * @return array{string, array<string, mixed>}|null
     */
    public function noteEditor(RecordView $view): ?array
    {
        if (!$this->milkdown || !$this->mentions instanceof Mentions || !class_exists(self::MILKDOWN_TYPE)) {
            return null;
        }
        $params = ['type' => $view->type->segment(), 'id' => $view->id];

        return [self::MILKDOWN_TYPE, [
            'suggestions'     => ['@' => $this->ui->url('nowo_crm_kit_mentions', $params)],
            'mention_display' => 'name',
            'user_title_url'  => $this->ui->url('nowo_crm_kit_mention_names', $params),
        ]];
    }

    /**
     * The notes' text with their mentions shown as names.
     *
     * @param list<NoteView> $notes
     *
     * @return array<string, string>
     */
    private function texts(array $notes): array
    {
        if (!$this->mentions instanceof Mentions || $notes === []) {
            return [];
        }
        $labels = $this->mentions->labels($this->access->base()->tenant);
        $texts  = [];
        foreach ($notes as $note) {
            $texts[$note->id] = Mentions::display($note->body, $labels);
        }

        return $texts;
    }

    /**
     * The edit, delete and `.ics` actions of an activity.
     *
     * @param array<string, string> $back
     *
     * @return list<Action>
     */
    private function engagementButtons(EngagementView $engagement, bool $change, string $token, array $back): array
    {
        $buttons = [];
        if ($change) {
            $buttons[] = $this->dialogs->enabled()
                ? $this->dialogs->opener('edit', 'activity_log.edit', 'nowo_crm_kit_activity_edit', ['id' => $engagement->id], 'crm-activity-edit')
                : new Action('edit', UiSupport::label('activity_log.edit'), $this->ui->url('nowo_crm_kit_activity_edit', ['id' => $engagement->id]), attributes: ['data-testid' => 'crm-activity-edit']);
        }
        if ($engagement->kind === ActivityKind::Meeting) {
            $buttons[] = new Action('download', UiSupport::label('activity_log.ics'), $this->ui->url('nowo_crm_kit_activity_ics', ['id' => $engagement->id]), icon: 'download', attributes: ['data-testid' => 'crm-activity-ics']);
        }
        if ($change) {
            $buttons[] = new Action('delete', UiSupport::label('activity_log.delete'), $this->ui->url('nowo_crm_kit_activity_delete', ['id' => $engagement->id] + $back), method: 'POST', csrfToken: $token, confirm: new Confirm(UiSupport::label('activity_log.delete_confirm'), null, UiSupport::label('activity_log.delete')), variant: 'danger', csrfField: 'crm_action[_token]');
        }

        return $buttons;
    }
}
