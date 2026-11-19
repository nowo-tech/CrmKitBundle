<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;

use function array_map;

/**
 * REQ-SEC-006 on every database: look-alike tenants and owners (case, accents, NFC / NFD) never reach each other's
 * rows; on MySQL / MariaDB the id and key columns carry a binary NO PAD collation.
 */
final class ExactIdsTest extends CrmTestCase
{
    public function testLookAlikeTenantsAndOwnersNeverMatch(): void
    {
        $this->boot();
        $tenants = ['bob', 'Bob', "b\u{00F3}b", "bo\u{0301}b"];
        $ids     = [];
        foreach ($tenants as $tenant) {
            $this->ctx()->tenant          = $tenant;
            $this->ctx()->owners[$tenant] = ['leo' => 'Leo', 'Leo' => 'Other Leo'];
            $this->ctx()->grant($tenant, 'ana', 'view', 'edit');
            $ids[$tenant] = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Of ' . $tenant, 'email' => 'same@x.test'], 'leo'));
            $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'Owned by Leo'], 'Leo'));
        }
        $reader = $this->service(CrmReaderInterface::class);
        foreach ($tenants as $tenant) {
            $this->ctx()->tenant = $tenant;
            self::assertSame(['Of ' . $tenant], array_map(static fn (RecordView $v): string => $v->label, $reader->list(new ListQuery(SubjectType::Contact, ownerId: 'leo'))->rows), $tenant);
            foreach ($ids as $other => $id) {
                if ($other === $tenant) {
                    self::assertSame('Of ' . $tenant, $reader->record(SubjectType::Contact, $id)->label);

                    continue;
                }
                try {
                    $reader->record(SubjectType::Contact, $id);
                    self::fail($tenant . ' read ' . $other);
                } catch (NotAvailableException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertSame(4, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE email_key = 'same@x.test'"), 'one e-mail per tenant, four look-alike tenants');
    }

    public function testMysqlColumnsUseABinaryNoPadCollation(): void
    {
        $this->boot();
        if (!$this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addToAssertionCount(1); // PostgreSQL and SQLite compare exactly by default

            return;
        }
        $rows = $this->connection()->fetchAllKeyValue("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nowo_crm_contact' AND COLUMN_NAME IN ('id', 'tenant_id', 'owner_id', 'email_key', 'display_name')");
        foreach (['id', 'tenant_id', 'owner_id', 'email_key'] as $column) {
            self::assertContains($rows[$column] ?? null, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin'], $column);
        }
        self::assertNotContains($rows['display_name'] ?? null, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin']);
    }
}
