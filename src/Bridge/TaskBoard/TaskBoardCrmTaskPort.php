<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\TaskBoard;

use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskState;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Entity\Task;

use function array_values;
use function substr;

/**
 * {@see CrmTaskPort} on TaskBoard (0.3.0, `pipeline.taskboard.board_id`): a next action becomes a task on the configured
 * board (and column), tagged `crm-<dedupe>` so a repeat finds it again, with the CRM record's path in its description.
 * It goes through TaskBoard's own API operations, so TaskBoard's levels, board rights and reach apply and nothing is
 * widened: an API call hands over the TaskBoard principal of the same token (`CrmTaskRequest::$hostContext`); a page
 * call acts as the actor's TaskBoard user ({@see CrmTaskBoardUserResolverInterface}) with that person's own rights. A TaskBoard
 * refusal keeps its code (`TOKEN_001`, `ACCESS_002`, …); 5xx refusals are transient. Stateless.
 */
final readonly class TaskBoardCrmTaskPort implements CrmTaskPort
{
    /** Task type of TaskBoard tasks. */
    public const TYPE = 'taskboard';

    /** Prefix of the dedupe tag. */
    public const TAG_PREFIX = 'crm-';

    /**
     * @param string|null $column the TaskBoard column (status name) new tasks go to; null: the board's first
     */
    public function __construct(
        private TaskApiTaskOperations $operations,
        private TaskApiReadOperations $reads,
        private CrmTaskBoardUserResolverInterface $users,
        private string $boardId,
        private ?string $column = null,
    ) {
    }

    /**
     * The task of the request: the one tagged with its dedupe key, else a new one.
     */
    public function create(CrmTaskRequest $request): CrmTaskRef
    {
        $principal = $this->principal($request->tenant, $request->actor, $request->hostContext);
        $tag       = self::tag($request->dedupeKey);

        try {
            $existing = $this->reads->tasks($principal, $this->boardId, new TaskApiTaskFilter(includeClosed: true, tags: [$tag]))->tasks[0] ?? null;
            if ($existing instanceof Task) {
                return new CrmTaskRef(self::TYPE, $existing->getId());
            }
            $fields = ['name' => $request->title, 'tags' => [$tag], 'due_date' => $request->dueAt->getTimestamp() * 1000];
            if ($this->column !== null) {
                $fields['status'] = $this->column;
            }
            if ($request->backLink !== null) {
                $fields['description'] = $request->backLink;
            }

            return new CrmTaskRef(self::TYPE, $this->operations->createTask($principal, $this->boardId, new TaskApiInput($fields))->getId());
        } catch (TaskApiRefusal $e) {
            throw new CrmTaskRefusedException($e->ecode, $e->status >= 500, $e);
        }
    }

    /**
     * The states of the tasks the actor's TaskBoard user may see (closed tasks are done).
     */
    public function states(TenantRef $tenant, ActorRef $actor, ?object $hostContext, array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if ($ref->type === self::TYPE) {
                $ids[$ref->id] = $ref->id;
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $principal = $this->principal($tenant, $actor, $hostContext);
            $tasks     = $this->reads->taskList($principal, array_values($ids))->tasks;
        } catch (TaskApiRefusal|CrmTaskRefusedException) {
            return [];
        }
        $states = [];
        foreach ($tasks as $task) {
            if (isset($ids[$task->getId()])) {
                $ref                 = new CrmTaskRef(self::TYPE, $task->getId());
                $states[$ref->key()] = new CrmTaskState($ref, $task->isCompleted() ? NextActionStatus::Done : NextActionStatus::Open);
            }
        }

        return $states;
    }

    /**
     * The dedupe tag of a key (96 bits of it: short enough for a tag).
     */
    public static function tag(string $dedupeKey): string
    {
        return self::TAG_PREFIX . substr($dedupeKey, 0, 24);
    }

    /**
     * The TaskBoard principal: the token's own (API calls), else the actor's TaskBoard user (its own rights apply).
     *
     * @throws CrmTaskRefusedException `ACCESS_002` when the actor has no TaskBoard user
     */
    private function principal(TenantRef $tenant, ActorRef $actor, ?object $hostContext): TaskApiPrincipal
    {
        if ($hostContext instanceof TaskApiPrincipal) {
            return $hostContext;
        }
        $user = $this->users->user($tenant, $actor);
        if ($user === null) {
            throw new CrmTaskRefusedException('ACCESS_002');
        }

        return new TaskApiPrincipal($user, TaskApiLevel::Tasks, TaskApiWhere::everything(), 'crm', 'CRM', 'crm');
    }
}
