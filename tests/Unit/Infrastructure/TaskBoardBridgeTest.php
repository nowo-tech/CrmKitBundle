<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Pipeline\BuiltInCrmTaskPort;
use Nowo\CrmKitBundle\Bridge\TaskBoard\CrmTaskBoardUserResolverInterface;
use Nowo\CrmKitBundle\Bridge\TaskBoard\TaskBoardCrmTaskPort;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskPage;
use Nowo\TaskBoardBundle\Entity\Task;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function end;
use function str_repeat;

/**
 * The TaskBoard bridge of next actions (0.3.0) against the TaskBoard API stand-ins, and the built-in port.
 */
final class TaskBoardBridgeTest extends TestCase
{
    public function testTheBridgeCreatesOnceAndKeepsTaskBoardRefusals(): void
    {
        /** @var list<array{TaskApiPrincipal, string, TaskApiInput}> $created */
        $created = [];
        /** @var list<list<string>> $tags */
        $tags = [];
        /** @var Task|null $existing */
        $existing = null;
        /** @var TaskApiRefusal|null $refusal */
        $refusal = null;
        $ops     = new TaskApiTaskOperations(static function (TaskApiPrincipal $p, string $board, TaskApiInput $input) use (&$created, &$refusal): Task {
            if ($refusal instanceof TaskApiRefusal) {
                throw $refusal;
            }
            $created[] = [$p, $board, $input];

            return new Task('t1');
        });
        $reads = new TaskApiReadOperations(
            static function (TaskApiPrincipal $p, string $board, TaskApiTaskFilter $filter) use (&$tags, &$existing): TaskApiTaskPage {
                $tags[] = $filter->tags;

                return new TaskApiTaskPage($existing instanceof Task ? [$existing] : []);
            },
            static fn (TaskApiPrincipal $p, array $ids): TaskApiTaskPage => new TaskApiTaskPage([new Task('t1', true), new Task('t9')]),
        );
        $user  = new stdClass();
        $users = new class($user) implements CrmTaskBoardUserResolverInterface {
            public function __construct(private ?object $user)
            {
            }

            public function user(TenantRef $tenant, ActorRef $actor): ?object
            {
                return $actor->value === 'ana' ? $this->user : null;
            }
        };
        $port = new TaskBoardCrmTaskPort($ops, $reads, $users, 'board-1', 'To do');
        $ref  = $port->create($this->request('ana', '/crm/deals/1'));
        self::assertSame(['taskboard', 't1'], [$ref->type, $ref->id]);
        [$principal, $board, $input] = $created[0];
        self::assertSame([$user, 'board-1', TaskApiLevel::Tasks, 'crm'], [$principal->user, $board, $principal->level, $principal->channel]);
        self::assertSame(['name' => 'Call', 'tags' => ['crm-' . str_repeat('a', 24)], 'due_date' => 1763632800000, 'status' => 'To do', 'description' => '/crm/deals/1'], $input->fields);
        self::assertSame([['crm-' . str_repeat('a', 24)]], $tags);

        // A repeat finds the tagged task; the token's own principal is used as is.
        $existing = new Task('t7');
        $token    = new TaskApiPrincipal(new stdClass(), TaskApiLevel::Read, TaskApiWhere::everything(), 'tok', 'Agent', 'mcp');
        self::assertSame('t7', $port->create($this->request('nobody', null, $token))->id);
        self::assertCount(1, $created);
        $existing = null;
        $without  = new TaskBoardCrmTaskPort($ops, $reads, $users, 'board-1');
        $without->create($this->request('ana', null));
        self::assertCount(2, $created);
        $second = end($created);
        self::assertIsArray($second);
        self::assertInstanceOf(TaskApiInput::class, $second[2]);
        self::assertSame(['name' => 'Call', 'tags' => ['crm-' . str_repeat('a', 24)], 'due_date' => 1763632800000], $second[2]->fields);

        $refusal = new TaskApiRefusal(403, 'TOKEN_001', 'no');
        try {
            $port->create($this->request('ana', null));
            self::fail('created');
        } catch (CrmTaskRefusedException $e) {
            self::assertSame(['TOKEN_001', false], [$e->errorCode, $e->transient]);
        }
        $refusal = new TaskApiRefusal(503, 'DOWN_001', 'down');
        try {
            $port->create($this->request('ana', null));
            self::fail('created');
        } catch (CrmTaskRefusedException $e) {
            self::assertTrue($e->transient);
        }
        try {
            $port->create($this->request('nobody', null));
            self::fail('created');
        } catch (CrmTaskRefusedException $e) {
            self::assertSame('ACCESS_002', $e->errorCode);
        }

        $states = $port->states(new TenantRef('acme'), new ActorRef('ana'), null, [new CrmTaskRef('taskboard', 't1'), new CrmTaskRef('crm', 'x')]);
        self::assertSame(['taskboard:t1'], array_keys($states));
        self::assertSame(NextActionStatus::Done, $states['taskboard:t1']->status);
        self::assertSame([], $port->states(new TenantRef('acme'), new ActorRef('ana'), null, [new CrmTaskRef('crm', 'x')]));
        self::assertSame([], $port->states(new TenantRef('acme'), new ActorRef('nobody'), null, [new CrmTaskRef('taskboard', 't1')]), 'no TaskBoard user: nothing shown');
    }

    public function testTheBuiltInPort(): void
    {
        $port = new BuiltInCrmTaskPort();
        self::assertSame(['crm', str_repeat('a', 64)], [$port->create($this->request('ana', null))->type, $port->create($this->request('ana', null))->id]);
        self::assertSame([], $port->states(new TenantRef('acme'), new ActorRef('ana'), null, []));
    }

    private function request(string $actor, ?string $back, ?object $context = null): CrmTaskRequest
    {
        return new CrmTaskRequest(new TenantRef('acme'), new ActorRef($actor), SubjectRef::of(SubjectType::Deal, '01900000-0000-7000-8000-000000000001'), 'Call', new DateTimeImmutable('2025-11-20 10:00:00 UTC'), null, str_repeat('a', 64), 'web', $context, $back);
    }
}
