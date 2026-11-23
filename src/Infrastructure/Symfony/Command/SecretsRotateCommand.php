<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Command;

use DateTimeZone;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function end;
use function sprintf;

/**
 * `nowo:crm-kit:secrets:rotate` (0.10.0): re-seals every sealed HubSpot token under `secrets.current_key`, one unit
 * per connection under its row lock. A token that no longer opens marks its connection invalid (a manager pastes a
 * new one). `--dry-run` only counts. Prints counts only, never a secret.
 */
#[AsCommand(name: 'nowo:crm-kit:secrets:rotate', description: 'Re-seal the CRM secrets under the current key')]
final class SecretsRotateCommand extends Command
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private readonly HubSpotStoreInterface $store, private readonly CrmSecretBoxInterface $box, private readonly TransactionRunnerInterface $runner, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be re-sealed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry    = $input->getOption('dry-run') === true;
        $counts = ['resealed' => 0, 'current' => 0, 'invalid' => 0];
        $after  = null;
        do {
            $batch = $this->runner->read(fn (): array => $this->store->connections($after, 100));
            foreach ($batch as $row) {
                $outcome = $this->runner->write(fn (): string => $this->rotate(new TenantRef($row['tenant']), $row['id'], $dry));
                ++$counts[$outcome];
            }
            $last  = end($batch);
            $after = $last === false ? null : $last['id'];
        } while ($after !== null);
        $output->writeln(sprintf('%s: %d re-sealed, %d already current, %d invalid', $dry ? 'dry run' : 'secrets', $counts['resealed'], $counts['current'], $counts['invalid']));

        return Command::SUCCESS;
    }

    /**
     * One connection: `resealed`, `current` or `invalid`.
     */
    private function rotate(TenantRef $tenant, string $id, bool $dry): string
    {
        $connection = $this->store->lockConnectionById($tenant, $id);
        if (!$connection instanceof HubSpotConnection || $connection->getToken()->keyId === $this->box->currentKeyId()) {
            return 'current';
        }
        $now     = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $context = new SecretContext(SecretContext::HUBSPOT_TOKEN, $tenant, $id);

        try {
            $plain = $this->box->unseal($connection->getToken(), $context);
        } catch (SecretUnsealException) {
            if (!$dry) {
                $this->store->invalidate($tenant, $id, $now);
            }

            return 'invalid';
        }
        if (!$dry) {
            $connection->reseal($this->box->seal($plain, $context), $now);
        }

        return 'resealed';
    }
}
