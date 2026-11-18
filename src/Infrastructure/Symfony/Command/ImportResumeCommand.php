<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Command;

use Nowo\CrmKitBundle\Application\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:crm-kit:import:resume` (contract section 15.4): takes over running imports whose claim is stale (a stopped
 * worker) and finishes them. Every few minutes.
 */
#[AsCommand(name: 'nowo:crm-kit:import:resume', description: 'Finishes imports whose worker stopped.')]
final class ImportResumeCommand extends Command
{
    /**
     * Wires the service.
     */
    public function __construct(private readonly ImportService $imports)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('imports finished: %d', $this->imports->resumeStale()));

        return Command::SUCCESS;
    }
}
