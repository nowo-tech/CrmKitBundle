<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;

use function dirname;
use function file_get_contents;
use function preg_match_all;
use function sort;

/**
 * Audit VD2-1 / VD2-2: the UPGRADING pre-check of the switch to stable actor ids, run exactly as documented on the
 * real databases. It flags reserved ids, ids stored in a column or only inside timeline / merge JSON documents that
 * the map does not replace, and two old ids mapped to one new id; a clean map passes.
 */
final class UpgradingPrecheckTest extends CrmTestCase
{
    public function testTheDocumentedPrecheckRunsAndFlagsEveryCollision(): void
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->boot();
        $this->ctx()->owners['acme']['zed'] = 'Zed';
        $this->ctx()->owners['acme']['zoe'] = 'Zoe';
        $this->actAs('ana', 'view', 'create', 'edit', 'manage');
        $reader = $this->service(CrmReaderInterface::class);
        $c      = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'C'], 'leo'));
        // zed only remains inside an owner-changed timeline entry (the owner moved on to leo).
        $this->records()->update($c, new RecordInput(SubjectType::Contact, [], 'zed'), $reader->record(SubjectType::Contact, $c)->version);
        $this->records()->update($c, new RecordInput(SubjectType::Contact, [], 'leo'), $reader->record(SubjectType::Contact, $c)->version);
        // zoe only remains inside a merge snapshot (the tombstone's owner is blanked).
        $m1 = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'T1'], 'ana'));
        $m2 = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'T2'], 'zoe'));
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $m1, $m2, [], $reader->record(SubjectType::Contact, $m1)->version, $reader->record(SubjectType::Contact, $m2)->version);
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE owner_id IN ('zed', 'zoe') OR created_by_id IN ('zed', 'zoe') OR updated_by_id IN ('zed', 'zoe')"), 'zed and zoe are in JSON only');

        $db       = $this->connection();
        $platform = $db->getDatabasePlatform();
        $doc      = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
        preg_match_all('/WITH stored_ids \(id\) AS \(.*?HAVING COUNT\(\*\) > 1\);/s', $doc, $queries);
        self::assertCount(2, $queries[0], 'the MySQL / MariaDB and the PostgreSQL pre-check');
        $precheck = $platform instanceof AbstractMySQLPlatform ? $queries[0][0] : $queries[0][1];
        $collate  = $platform instanceof MariaDBPlatform ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin' : ($platform instanceof AbstractMySQLPlatform ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin' : '');
        $db->executeStatement('DROP TABLE IF EXISTS crm_actor_map');
        $db->executeStatement('CREATE TABLE crm_actor_map (old_id VARCHAR(128)' . $collate . ' NOT NULL PRIMARY KEY, new_id VARCHAR(128)' . $collate . ' NOT NULL)');
        $flagged = static function (array $map) use ($db, $precheck): array {
            $db->executeStatement('DELETE FROM crm_actor_map');
            foreach ($map as $old => $new) {
                $db->insert('crm_actor_map', ['old_id' => $old, 'new_id' => $new]);
            }
            $ids = $db->fetchFirstColumn($precheck);
            sort($ids);

            return $ids;
        };

        try {
            self::assertSame([], $flagged(['ana' => 'uuid-ana', 'leo' => 'uuid-leo', 'zed' => 'uuid-zed', 'zoe' => 'uuid-zoe']), 'a clean map passes');
            self::assertSame([], $flagged(['ana' => 'leo', 'leo' => 'uuid-leo']), 'an id the map replaces may be reused');
            self::assertSame(['leo'], $flagged(['ana' => 'leo']), 'a stored id in a column');
            self::assertSame(['zed'], $flagged(['ana' => 'zed']), 'an id stored only inside a timeline entry');
            self::assertSame(['zoe'], $flagged(['ana' => 'zoe']), 'an id stored only inside a merge snapshot');
            self::assertSame(['uuid-x', 'uuid-x'], $flagged(['ana' => 'uuid-x', 'leo' => 'uuid-x']), 'two old ids on one new id');
            self::assertSame(['anonymised', 'anonymous'], $flagged(['ana' => 'anonymised', 'leo' => 'anonymous']), 'reserved ids');
        } finally {
            $db->executeStatement('DROP TABLE crm_actor_map');
        }
    }
}
