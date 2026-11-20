<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Command;

use DateTimeZone;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * Deletes the expired idempotency keys of the API (0.3.0) in batches of 500, outside any write unit. The daily
 * `nowo:crm-kit:purge` runs it too.
 */
#[AsCommand(name: 'nowo:crm-kit:api:purge-idempotency', description: 'Delete expired API idempotency keys')]
final class ApiPurgeIdempotencyCommand extends Command
{
    /** Rows per batch. */
    public const BATCH = 500;

    /**
     * Wires the store and the clock.
     */
    public function __construct(private readonly CrmApiIdempotencyStoreInterface $store, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    /**
     * Deletes every expired key; how many.
     */
    public static function purge(CrmApiIdempotencyStoreInterface $store, ClockInterface $clock): int
    {
        $now   = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $total = 0;
        do {
            $deleted = $store->purgeExpired($now, self::BATCH);
            $total += $deleted;
        } while ($deleted === self::BATCH);

        return $total;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('expired idempotency keys: %d', self::purge($this->store, $this->clock)));

        return Command::SUCCESS;
    }
}
