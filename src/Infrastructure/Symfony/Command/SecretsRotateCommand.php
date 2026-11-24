<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Command;

use DateTimeZone;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function end;
use function hash_equals;
use function sprintf;

/**
 * `nowo:crm-kit:secrets:rotate` (0.10.0): re-seals every sealed HubSpot token under the box's current key, one unit per
 * connection under its row lock. `--all` re-seals every token (also those under the current key id). `--dry-run` does
 * the same work without writing (so it also proves the new key seals). Prints counts only, never a secret.
 *
 * Never loses a token (0.10.1, audit ZAM-D2): a new seal is written only after it opened again to the same plaintext,
 * and a token that does not open, whose key is not available, or that cannot be re-sealed is left exactly as it is
 * (no connection is marked invalid here; the import does that on use for a value that does not open). The command then
 * exits with 1 so the old key is not dropped yet; connections already invalid whose token does not open are reported
 * apart and never fail the run (audit ZAW-I5: they wait for a new token whatever the keys). Concurrency: the row lock serialises it with a token replace; an
 * import that read the old value meanwhile still opens it (the old key stays listed until the rotation is done), and
 * its invalidation is conditional on the value it used, so it never hits the re-sealed one.
 */
#[AsCommand(name: 'nowo:crm-kit:secrets:rotate', description: 'Re-seal the CRM secrets under the current key')]
final class SecretsRotateCommand extends Command
{
    /**
     * Wires the collaborators.
     *
     * @param LoggerInterface|null $logger where an unavailable key is reported (0.10.1, audit ZAW-I2: key id and the
     *                                     cause's class only)
     */
    public function __construct(private readonly HubSpotStoreInterface $store, private readonly CrmSecretBoxInterface $box, private readonly TransactionRunnerInterface $runner, private readonly ClockInterface $clock, private readonly ?LoggerInterface $logger = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Re-seal in memory and count, write nothing');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Re-seal every secret, also those under the current key id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry    = $input->getOption('dry-run') === true;
        $all    = $input->getOption('all') === true;
        $counts = ['resealed' => 0, 'current' => 0, 'unopened' => 0, 'unavailable' => 0, 'failed' => 0, 'invalid' => 0];
        $after  = null;
        do {
            $batch = $this->runner->read(fn (): array => $this->store->connections($after, 100));
            foreach ($batch as $row) {
                $outcome = $this->runner->write(fn (): string => $this->rotate(new TenantRef($row['tenant']), $row['id'], $dry, $all));
                ++$counts[$outcome];
            }
            $last  = end($batch);
            $after = $last === false ? null : $last['id'];
        } while ($after !== null);
        $kept = $counts['unopened'] + $counts['unavailable'] + $counts['failed'];
        $output->writeln(sprintf('%s: %d re-sealed, %d already current, %d left unchanged (%d do not open, %d key not available, %d could not be re-sealed)', $dry ? 'dry run' : 'secrets', $counts['resealed'], $counts['current'], $kept, $counts['unopened'], $counts['unavailable'], $counts['failed']));
        if ($counts['invalid'] > 0) {
            // Audit ZAW-I5: connections already invalid wait for a new token; they never block the rotation.
            $output->writeln(sprintf('%d invalid connection(s) whose token does not open were skipped: disconnect them or paste a new token.', $counts['invalid']));
        }
        if ($kept > 0) {
            $output->writeln('Keep every old key configured until a run leaves nothing unchanged.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * One connection: `resealed`, `current`, `unopened`, `unavailable`, `failed` or `invalid` (an invalid connection
     * whose token does not open, reported apart and never failing the run); only `resealed` changes something.
     */
    private function rotate(TenantRef $tenant, string $id, bool $dry, bool $all): string
    {
        $connection = $this->store->lockConnection($tenant);
        // Replaced by another connection since it was listed, or already sealed under the current key (unless --all).
        if (!$connection instanceof HubSpotConnection || $connection->getId() !== $id || (!$all && $connection->getToken()->keyId === $this->box->currentKeyId())) {
            return 'current';
        }
        $context = new SecretContext(SecretContext::HUBSPOT_TOKEN, $tenant, $id);

        try {
            $plain = $this->box->unseal($connection->getToken(), $context);
        } catch (SecretUnsealException) {
            return $connection->isActive() ? 'unopened' : 'invalid';
        } catch (SecretKeyUnavailableException $e) {
            $this->logger?->error('nowo_crm_kit: a HubSpot token\'s secret key is not available', ['connection' => $id] + $e->logContext());

            return 'unavailable';
        }

        try {
            // The new seal is kept only once it opens again to the same token.
            $sealed = $this->box->seal($plain, $context);
            if (!hash_equals($plain, $this->box->unseal($sealed, $context))) {
                return 'failed';
            }
        } catch (SecretUnsealException) {
            return 'failed';
        } catch (SecretKeyUnavailableException $e) {
            $this->logger?->error('nowo_crm_kit: the secret key that seals HubSpot tokens is not available', ['connection' => $id] + $e->logContext());

            return 'failed';
        }
        if (!$dry) {
            $connection->reseal($sealed, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
        }

        return 'resealed';
    }
}
