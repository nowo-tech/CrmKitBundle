<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiNextActionDraft;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiPresenter;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function hash;
use function is_string;

/**
 * `crm_next_action_set` (0.3.0, design §15.4): the task port runs before the CRM's write unit with a dedupe key derived
 * from the idempotency hashes (a retry with the same key adopts the task it created); the port gets the principal's
 * host context so the host applies the same token (MCP never widens it). Stateless.
 */
final readonly class CrmApiNextActionOperations
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmApiGuard $guard,
        private CrmApiWriter $writer,
        private CrmApiRecords $records,
        private ?CrmApiPipeline $pipeline = null,
    ) {
    }

    /**
     * Creates one next action on a company, contact or deal; a deal's `next_action_at` follows the earliest due open
     * action. Codes: the port's own (`TOKEN_001`, `ACCESS_002`, …), `CRM_009` (transient, nothing written), `CRM_010`,
     * `CONFLICT_002` after the port committed.
     */
    public function set(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, CrmApiNextActionDraft $draft): CrmApiWritten
    {
        $call     = $this->guard->call($principal, $tenant, CrmApiOperation::NextActionSet);
        $pipeline = $this->pipeline ?? throw CrmApiRefusal::notFound();
        $dedupe   = hash('sha256', CrmApiIdempotency::scope($principal->channel, $principal->tokenId) . "\n" . $tenant->value . "\n" . $key->value);

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::NextActionSet,
            $draft->canonical(),
            201,
            static fn (mixed $id): array => ['next_action' => is_string($id) ? $id : ''],
            function (CrmApiCall $bound) use ($call, $draft, $dedupe, $pipeline): CrmApiWritten {
                $bound->require(CrmApiCapability::Edit, $this->subject($bound, $draft));
                $id = CrmApiErrors::run(static fn (): string => $pipeline->nextActions->as($bound->caller)->set($draft->type, $draft->subjectId->value, $draft->title, $draft->dueAt, $draft->assignee?->value, $dedupe, $bound->principal->hostContext));

                return new CrmApiWritten($this->action($call, $draft, $id), false, true, 201);
            },
            function (CrmApiReplay $r) use ($call, $draft): CrmApiWritten {
                $this->subject($call, $draft);

                return new CrmApiWritten($this->action($call, $draft, $r->ids['next_action'] ?? ''), true, true, $r->status);
            },
        );
    }

    /**
     * The subject after the reach checks (404 when out of reach).
     */
    private function subject(CrmApiCall $call, CrmApiNextActionDraft $draft): SubjectRef
    {
        if ($draft->type === SubjectType::Deal) {
            $view = CrmApiErrors::run(fn (): DealView => ($this->pipeline ?? throw CrmApiRefusal::notFound())->deals->as($call->caller)->deal($draft->subjectId->value));

            return SubjectRef::of(SubjectType::Deal, $view->id, $view->ownerId);
        }

        return $this->records->probe($call, $draft->type, $draft->subjectId) ?? throw CrmApiRefusal::notFound();
    }

    private function action(CrmApiCall $call, CrmApiNextActionDraft $draft, string $id): CrmApiItem
    {
        $pipeline = $this->pipeline ?? throw CrmApiRefusal::notFound();
        foreach (CrmApiErrors::run(static fn (): array => $pipeline->nextActions->as($call->caller)->list($draft->type, $draft->subjectId->value)) as $view) {
            if ($view->id === $id) {
                return CrmApiPresenter::nextAction($view);
            }
        }

        throw CrmApiRefusal::notFound();
    }
}
