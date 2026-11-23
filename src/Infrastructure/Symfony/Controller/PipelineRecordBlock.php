<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\PartyStageService;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\PartyStageView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\LifecycleType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NextActionType;
use Nowo\UiKitBundle\View\Action;

/**
 * The funnels' block of the 360° page of a company or contact (0.3.0): its visible deals, its lifecycle stage in each
 * party funnel of its type (with the form to change it), and its next actions (with the add form and the done / cancel
 * buttons). Stateless.
 *
 * @internal
 */
final readonly class PipelineRecordBlock
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private UiSupport $ui,
        private FunnelReader $funnels,
        private DealReader $deals,
        private PartyStageService $lifecycle,
        private NextActionService $actions,
        private PipelineForms $forms,
    ) {
    }

    /**
     * The block's variables for the record page (`$edit`: the actor may edit the record).
     *
     * @return array<string, mixed>
     */
    public function variables(RecordView $view, bool $edit): array
    {
        $current = [];
        foreach ($this->lifecycle->stages($view->type, $view->id) as $stage) {
            $current[$stage->funnelId] = $stage;
        }
        $lifecycle = [];
        $choices   = [];
        foreach ($this->funnels->funnels(FunnelKind::Party) as $funnel) {
            if ($funnel->subjectType !== $view->type) {
                continue;
            }
            $at          = $current[$funnel->id] ?? null;
            $lifecycle[] = ['funnel' => $funnel, 'stage' => $at instanceof PartyStageView ? $funnel->stage($at->stageId) : null, 'since' => $at?->enteredAt];
            foreach ($this->forms->stages($funnel) as $label => $stageId) {
                $choices[$funnel->name . ' › ' . $label] = $funnel->id . '|' . $stageId;
            }
        }
        $token   = $this->ui->actionToken();
        $actions = $this->actions->list($view->type, $view->id);
        $buttons = [];
        $forms   = [];
        if ($edit) {
            foreach ($actions as $action) {
                foreach (['done', 'cancel'] as $kind) {
                    $buttons[$action->id][] = new Action($kind === 'done' ? 'mark_done' : 'cancel', UiSupport::label('next_action.' . $kind), $this->ui->url('nowo_crm_kit_next_action_close', ['id' => $action->id, 'action' => $kind, 'back_type' => $view->type->segment(), 'back' => $view->id]), method: 'POST', csrfToken: $token, csrfField: 'crm_action[_token]');
                }
            }
            $forms['action'] = $this->ui->form(NextActionType::class, ['dedupe' => PipelineForms::dedupe(), 'due_at' => new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC'))], ['owners' => $this->forms->owners()], 'crm_next_action')->createView();
            if ($choices !== []) {
                $forms['lifecycle'] = $this->ui->form(LifecycleType::class, null, ['stages' => $choices], 'crm_lifecycle')->createView();
            }
        }

        return [
            'deals'     => $this->deals->ofRecord($view->type, $view->id),
            'lifecycle' => $lifecycle,
            'actions'   => $actions,
            'buttons'   => $buttons,
            'forms'     => $forms,
        ];
    }
}
