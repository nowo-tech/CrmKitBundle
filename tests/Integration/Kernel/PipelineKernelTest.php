<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use Closure;
use LogicException;
use Nowo\CrmKitBundle\Application\Pipeline\BuiltInCrmTaskPort;
use Nowo\CrmKitBundle\Bridge\TaskBoard\CrmTaskBoardUserResolverInterface;
use Nowo\CrmKitBundle\Bridge\TaskBoard\TaskBoardCrmTaskPort;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTaskPort;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskPage;
use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The funnels' container (0.3.0): the task port (built-in, `pipeline.task_port`, the TaskBoard bridge and its
 * refusals), the lost reasons' validation.
 */
final class PipelineKernelTest extends KernelTestCase
{
    public function testTheTaskPortChoices(): void
    {
        $builtIn = $this->bootKernel(TestServices::PORTS + ['pipeline' => ['enabled' => true]])->getContainer()->get(CrmTaskPort::class);
        self::assertInstanceOf(BuiltInCrmTaskPort::class, $builtIn);
        $host = $this->bootKernel(TestServices::PORTS + ['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']])->getContainer()->get(CrmTaskPort::class);
        self::assertInstanceOf(TestTaskPort::class, $host);
        $bridge = $this->bootKernel(TestServices::PORTS + ['pipeline' => ['enabled' => true, 'taskboard' => ['board_id' => 'b1', 'column' => 'To do']]], $this->taskBoard(true))->getContainer();
        $bridge->set(TaskApiTaskOperations::class, new TaskApiTaskOperations(static fn (): Task => new Task('t1')));
        $bridge->set(TaskApiReadOperations::class, new TaskApiReadOperations(static fn (): TaskApiTaskPage => new TaskApiTaskPage([]), static fn (): TaskApiTaskPage => new TaskApiTaskPage([])));
        self::assertInstanceOf(TaskBoardCrmTaskPort::class, $bridge->get(CrmTaskPort::class));
        self::assertFalse($this->bootKernel(TestServices::PORTS)->getContainer()->has(CrmTaskPort::class), 'no funnels, no port');
    }

    public function testTheBridgeNeedsTaskBoard(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs nowo-tech/task-board-bundle');
        $this->bootKernel(TestServices::PORTS + ['pipeline' => ['enabled' => true, 'taskboard' => ['board_id' => 'b1']]]);
    }

    public function testTheBridgeNeedsAUserResolver(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(CrmTaskBoardUserResolverInterface::class);
        $this->bootKernel(TestServices::PORTS + ['pipeline' => ['enabled' => true, 'taskboard' => ['board_id' => 'b1']]], $this->taskBoard(false));
    }

    public function testLostReasonsAreKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('pipeline.lost_reasons');
        $this->bootKernel(TestServices::PORTS + ['pipeline' => ['enabled' => true, 'lost_reasons' => ['Too pricey']]]);
    }

    /**
     * The standard host plus TaskBoard's API operations (synthetic) and, optionally, the user resolver.
     *
     * @return Closure(ContainerConfigurator, string): void
     */
    private function taskBoard(bool $resolver): Closure
    {
        $standard = Host::standard()->configure();

        return static function (ContainerConfigurator $container, string $url) use ($standard, $resolver): void {
            $standard($container, $url);
            $services = $container->services();
            $services->set(TaskApiTaskOperations::class)->synthetic()->public();
            $services->set(TaskApiReadOperations::class)->synthetic()->public();
            if ($resolver) {
                $services->set(CrmTaskBoardUserResolverInterface::class, NobodyTaskBoardUsers::class);
            }
        };
    }
}

/**
 * A user resolver of the tests: nobody has a TaskBoard user.
 */
final class NobodyTaskBoardUsers implements CrmTaskBoardUserResolverInterface
{
    public function user(TenantRef $tenant, ActorRef $actor): ?object
    {
        return null;
    }
}
