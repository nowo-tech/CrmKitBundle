<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Command;

use Nowo\CrmKitBundle\Application\PlanCounter;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:crm-kit:counters:rebuild` (contract section 15.1): recounts the active records per tenant (after manual SQL).
 */
#[AsCommand(name: 'nowo:crm-kit:counters:rebuild', description: 'Recounts the plan-limit counters of every tenant.')]
final class CountersRebuildCommand extends Command
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private readonly CrmStoreInterface $store, private readonly PlanCounter $counter, private readonly TransactionRunnerInterface $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->runner->read(fn (): array => $this->store->tenants()) as $tenant) {
            $ref = new TenantRef($tenant);
            foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
                $count = $this->runner->write(fn (): int => $this->counter->rebuild($ref, $type));
                $output->writeln(sprintf('%s %s: %d', $tenant, $type->resource()->value, $count));
            }
        }

        return Command::SUCCESS;
    }
}
