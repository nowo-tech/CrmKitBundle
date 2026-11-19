<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Closure;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskState;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function count;
use function in_array;

/**
 * A host task port of the tests (`pipeline.task_port: test.task_port`): it "creates" a task per dedupe key (a repeat
 * returns the same one), can refuse with a code, and shows only the tasks listed in `$visible`.
 */
final class TestTaskPort implements CrmTaskPort
{
    /** @var array<string, string> dedupe key => task id */
    public array $tasks = [];

    /** Calls of create(). */
    public int $calls = 0;

    /** A code to refuse with (null: accept). */
    public ?string $refuse = null;

    /** Whether the refusal is transient. */
    public bool $transient = false;

    /** @var list<string> task ids states() returns */
    public array $visible = [];

    /** Runs once inside the next create() (a concurrent request of the same key, say). */
    public ?Closure $onCreate = null;

    public function create(CrmTaskRequest $request): CrmTaskRef
    {
        ++$this->calls;
        $during         = $this->onCreate;
        $this->onCreate = null;
        if ($during instanceof Closure) {
            $during();
        }
        if ($this->refuse !== null) {
            throw new CrmTaskRefusedException($this->refuse, $this->transient);
        }
        $this->tasks[$request->dedupeKey] ??= 'task-' . (count($this->tasks) + 1);

        return new CrmTaskRef('host', $this->tasks[$request->dedupeKey]);
    }

    public function states(TenantRef $tenant, ActorRef $actor, ?object $hostContext, array $refs): array
    {
        $states = [];
        foreach ($refs as $ref) {
            if (in_array($ref->id, $this->visible, true)) {
                $states[$ref->key()] = new CrmTaskState($ref, NextActionStatus::Open, '/tasks/' . $ref->id);
            }
        }

        return $states;
    }
}
