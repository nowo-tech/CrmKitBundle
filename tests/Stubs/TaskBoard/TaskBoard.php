<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/task-board-bundle's API classes used by the CRM's TaskBoard bridge, loaded by
// tests/bootstrap.php only when TaskBoard is not installed. Same names and the signatures the bridge calls; the
// operations answer from public closures so the tests can script them.

namespace Nowo\TaskBoardBundle\Entity {
    class Task
    {
        public function __construct(private string $id, private bool $completed = false)
        {
        }

        public function getId(): string
        {
            return $this->id;
        }

        public function isCompleted(): bool
        {
            return $this->completed;
        }
    }
}

namespace Nowo\TaskBoardBundle\Api {
    use RuntimeException;

    enum TaskApiLevel: string
    {
        case MyTasks = 'my_tasks';
        case Read    = 'read';
        case Tasks   = 'tasks';
        case Full    = 'full';
    }

    final readonly class TaskApiWhere
    {
        /**
         * @param list<string> $exceptTenantKeys
         */
        private function __construct(public array $exceptTenantKeys)
        {
        }

        /**
         * @param list<string> $exceptTenantKeys
         */
        public static function everything(array $exceptTenantKeys = []): self
        {
            return new self($exceptTenantKeys);
        }
    }

    final readonly class TaskApiPrincipal
    {
        public function __construct(
            public object $user,
            public TaskApiLevel $level,
            public TaskApiWhere $where,
            public string $tokenId,
            public string $tokenName,
            public string $channel = 'api',
        ) {
        }
    }

    final class TaskApiInput
    {
        /**
         * @param array<string, mixed> $fields
         */
        public function __construct(public readonly array $fields)
        {
        }
    }

    final class TaskApiRefusal extends RuntimeException
    {
        /**
         * @param array<string, mixed> $extra
         */
        public function __construct(public readonly int $status, public readonly string $ecode, string $message, public readonly array $extra = [])
        {
            parent::__construct($message);
        }
    }
}

namespace Nowo\TaskBoardBundle\Api\Value {
    use Nowo\TaskBoardBundle\Entity\Task;

    final readonly class TaskApiTaskFilter
    {
        /**
         * @param list<string> $tags
         */
        public function __construct(public bool $includeClosed = false, public array $tags = [])
        {
        }
    }

    final readonly class TaskApiTaskPage
    {
        /**
         * @param list<Task> $tasks
         */
        public function __construct(public array $tasks)
        {
        }
    }
}

namespace Nowo\TaskBoardBundle\Api\Operation {
    use Closure;
    use Nowo\TaskBoardBundle\Api\TaskApiInput;
    use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
    use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
    use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskPage;
    use Nowo\TaskBoardBundle\Entity\Task;

    final class TaskApiTaskOperations
    {
        /**
         * @param Closure(TaskApiPrincipal, string, TaskApiInput): Task $create
         */
        public function __construct(public Closure $create)
        {
        }

        public function createTask(TaskApiPrincipal $principal, string $boardId, TaskApiInput $input): Task
        {
            return ($this->create)($principal, $boardId, $input);
        }
    }

    final class TaskApiReadOperations
    {
        /**
         * @param Closure(TaskApiPrincipal, string, TaskApiTaskFilter): TaskApiTaskPage $tasks
         * @param Closure(TaskApiPrincipal, list<string>): TaskApiTaskPage $taskList
         */
        public function __construct(public Closure $tasks, public Closure $taskList)
        {
        }

        public function tasks(TaskApiPrincipal $principal, string $boardId, TaskApiTaskFilter $filter): TaskApiTaskPage
        {
            return ($this->tasks)($principal, $boardId, $filter);
        }

        /**
         * @param list<string> $taskIds
         */
        public function taskList(TaskApiPrincipal $principal, array $taskIds): TaskApiTaskPage
        {
            return ($this->taskList)($principal, $taskIds);
        }
    }
}
