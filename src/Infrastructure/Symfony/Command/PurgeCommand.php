<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Command;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\RecordPurger;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:crm-kit:purge` (contract section 16.4): the retention purge, daily.
 */
#[AsCommand(name: 'nowo:crm-kit:purge', description: 'Deletes trashed records and deals, merge tombstones, old timeline entries, import rows and merge traces past their retention.')]
final class PurgeCommand extends Command
{
    /**
     * Wires the purger.
     */
    public function __construct(private readonly RecordPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Only this tenant');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted (trashed records and deals only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = $input->getOption('tenant');
        try {
            $only = is_string($tenant) ? new TenantRef($tenant) : null;
        } catch (InvalidArgumentException) {
            $output->writeln('<error>Invalid tenant.</error>');

            return Command::INVALID;
        }
        $counts = $this->purger->purge($only, $input->getOption('dry-run') === true);
        if ($counts === null) {
            $output->writeln('Another purge is running; nothing (more) done.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('records: %d, merge tombstones: %d, timeline entries: %d, import rows: %d, merge traces: %d, deals: %d', $counts['records'], $counts['tombstones'], $counts['activities'], $counts['import_rows'], $counts['merges'], $counts['deals']));

        return Command::SUCCESS;
    }
}
