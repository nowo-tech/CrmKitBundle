<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\BinaryCollation;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\CrmMetadataListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The metadata listener: table, index and unique-constraint prefixes; binary NO PAD collations on the id, reference
 * and key columns on MySQL / MariaDB (REQ-SEC-006 rule 3); host classes untouched.
 */
final class MetadataListenerTest extends TestCase
{
    public function testPrefixesAndCollations(): void
    {
        $metadata = $this->contactMetadata();
        (new CrmMetadataListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->manager(new MariaDBPlatform())));
        self::assertSame('app_crm_contact', $metadata->getTableName());
        self::assertArrayHasKey('app_crm_contact_email', $metadata->table['uniqueConstraints'] ?? []);
        self::assertSame(BinaryCollation::MARIADB, $metadata->fieldMappings['emailKey']->options['collation'] ?? null);
        self::assertSame(BinaryCollation::MARIADB, $metadata->fieldMappings['tenantId']->options['collation'] ?? null);
        self::assertArrayNotHasKey('collation', $metadata->fieldMappings['displayName']->options ?? []);
    }

    public function testOtherPlatformsAndClasses(): void
    {
        $metadata = $this->contactMetadata();
        (new CrmMetadataListener())->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->manager(new SQLitePlatform())));
        self::assertSame('nowo_crm_contact', $metadata->getTableName());
        self::assertArrayNotHasKey('collation', $metadata->fieldMappings['emailKey']->options ?? []);
        $host = new ClassMetadata(stdClass::class);
        $host->setPrimaryTable(['name' => 'host']);
        (new CrmMetadataListener())->loadClassMetadata(new LoadClassMetadataEventArgs($host, $this->manager(new SQLitePlatform())));
        self::assertSame('host', $host->getTableName());
    }

    public function testAnUnreachablePlatformFailsWithTheFix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('down'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('server_version');
        (new CrmMetadataListener())->loadClassMetadata(new LoadClassMetadataEventArgs($this->contactMetadata(), $em));
    }

    /**
     * @return ClassMetadata<Contact>
     */
    private function contactMetadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(Contact::class);
        $metadata->setPrimaryTable(['name' => 'crm_contact', 'uniqueConstraints' => ['crm_contact_email' => ['columns' => ['tenant_id', 'email_key']]]]);
        foreach (['id' => 'id', 'tenantId' => 'tenant_id', 'emailKey' => 'email_key', 'displayName' => 'display_name'] as $field => $column) {
            $metadata->mapField(['fieldName' => $field, 'columnName' => $column, 'type' => 'string', 'length' => 128]);
        }
        $metadata->mapField(['fieldName' => 'version', 'columnName' => 'version', 'type' => 'integer']);

        return $metadata;
    }

    private function manager(object $platform): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }
}
