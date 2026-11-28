<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\PartyStage;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Model\Template\TemplatePurpose;
use ReflectionClass;
use RuntimeException;

use function str_pad;

use const STR_PAD_LEFT;

/**
 * Doctrine listener of the race tests: a racing writer acts inside our flush, as a concurrent request would.
 * - `emailRaces`: stores another contact with the same e-mail key just before ours (the unique key refuses ours);
 * - `bumpVersions`: moves the version of the record we update (our optimistic lock fails).
 */
final class RaceInjector
{
    private int $twins = 0;

    public function __construct(private readonly TestContext $context)
    {
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        $short  = (new ReflectionClass($object))->getShortName();
        if ($object instanceof NextAction && ($this->context->races['NextActionLock'] ?? 0) > 0) {
            --$this->context->races['NextActionLock'];

            throw new LockWaitTimeoutException(new class('Lock wait timeout exceeded.') extends AbstractException {}, null);
        }
        if ($object instanceof CrmRecord && ($this->context->races['DeadlockSkip'] ?? 0) > 0) {
            // Audit XU-L2: let this many records through first (a chunk that commits), then deadlock.
            --$this->context->races['DeadlockSkip'];
        } elseif ($object instanceof CrmRecord && ($this->context->races['Deadlock'] ?? 0) > 0) {
            --$this->context->races['Deadlock'];

            throw new DeadlockException(new class('Deadlock found when trying to get lock.') extends AbstractException {}, null);
        }
        if (($this->context->races[$short] ?? 0) > 0) {
            --$this->context->races[$short];
            $this->insertTwin($args->getObjectManager(), $object);
        }
        if (!$object instanceof Contact || $this->context->emailRaces <= 0 || $object->getEmailKey() === null) {
            return;
        }
        --$this->context->emailRaces;
        $em = $args->getObjectManager();
        $em->getConnection()->insert($em->getClassMetadata(Contact::class)->getTableName(), [
            'id'           => '01900000-0000-7000-8000-' . str_pad((string) $this->context->emailRaces, 12, '0', STR_PAD_LEFT),
            'tenant_id'    => $object->getTenantId(),
            'last_name'    => 'Racer',
            'display_name' => 'Racer',
            'sort_key'     => 'racer',
            'email'        => $object->value('email'),
            'email_key'    => $object->getEmailKey(),
            'custom'       => '{}',
            'status'       => 'active',
            'version'      => 1,
            'created_at'   => '2026-11-18 10:00:00',
            'updated_at'   => '2026-11-18 10:00:00',
        ]);
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();
        if ($object instanceof Engagement && ($this->context->races['LostSavepoint'] ?? 0) > 0) {
            // 0.11.0 (audit ZCR-L6): a deadlock inside the ORM flush's savepoint, as MySQL / MariaDB report it: the
            // server already ended the transaction, so ROLLBACK TO SAVEPOINT fails (1305) and replaces the deadlock.
            --$this->context->races['LostSavepoint'];
            $args->getObjectManager()->getConnection()->executeStatement('ROLLBACK');

            throw new DeadlockException(new class('Deadlock found when trying to get lock.') extends AbstractException {}, null);
        }
        if ($object instanceof Tag && ($this->context->races['TagRename'] ?? 0) > 0) {
            --$this->context->races['TagRename'];
            $this->insertTwin($args->getObjectManager(), $object);
        }
        if (!$object instanceof CrmRecord || !$this->context->bumpVersions) {
            return;
        }
        $this->context->bumpVersions = false;
        $em                          = $args->getObjectManager();
        $em->getConnection()->executeStatement('UPDATE ' . $em->getClassMetadata($object::class)->getTableName() . ' SET version = version + 1 WHERE id = ?', [$object->getId()]);
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        if ($args->getObject() instanceof CrmRecord && $this->context->failOnRemove) {
            $this->context->failOnRemove = false;

            throw new RuntimeException('Injected failure inside the unit.');
        }
    }

    /**
     * Stores a row with the same unique key as `$object` (another writer's), on our connection.
     */
    private function insertTwin(EntityManagerInterface $em, object $object): void
    {
        $id    = '01900000-0000-7000-8000-' . str_pad((string) ++$this->twins, 12, '0', STR_PAD_LEFT);
        $at    = '2026-11-18 10:00:00';
        $table = $em->getClassMetadata($object::class)->getTableName();
        $row   = match (true) {
            $object instanceof FieldDefinition => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'subject_type' => $object->getSubjectType()->value, 'field_key' => $object->getFieldKey(), 'label' => 'Racer', 'type' => 'text', 'choices' => '[]', 'required' => 0, 'position' => 0, 'archived' => 0, 'version' => 1, 'created_at' => $at],
            $object instanceof HostLink        => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'subject_type' => $object->getSubjectType()->value, 'subject_id' => $object->getSubjectId(), 'host_type' => $object->getHostType(), 'host_id' => $object->getHostId(), 'created_at' => $at],
            $object instanceof Affiliation     => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'contact_id' => $object->getContactId(), 'company_id' => $object->getCompanyId(), 'is_primary' => 0, 'created_at' => $at],
            $object instanceof CrmSettings     => ['tenant_id' => $object->getTenantId(), 'trash_days' => 30, 'timeline_years' => 5, 'import_keep_days' => 30, 'merge_keep_days' => 90, 'version' => 1, 'updated_at' => $at],
            $object instanceof Tag             => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'name' => $object->getName(), 'name_key' => $object->getNameKey(), 'colour' => 'red', 'version' => 1, 'created_at' => $at],
            $object instanceof RoleAssignment  => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'subject_type' => $object->getSubjectType()->value, 'subject_id' => $object->getSubjectId(), 'role_key' => $object->getRoleKey(), 'created_at' => $at],
            $object instanceof NextAction      => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'subject_type' => $object->getSubjectType()->value, 'subject_id' => $object->getSubjectId(), 'title' => 'Racer', 'due_at' => $at, 'status' => 'open', 'dedupe_key' => $object->getDedupeKey(), 'created_at' => $at, 'version' => 1],
            $object instanceof PartyStage      => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'funnel_id' => $object->getFunnelId(), 'subject_type' => $object->getSubjectType()->value, 'subject_id' => $object->getSubjectId(), 'stage_id' => $object->getStageId(), 'entered_at' => $at . '.000000', 'version' => 1],
            $object instanceof Tagging         => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'tag_id' => $object->getTagId(), 'subject_type' => $object->getSubjectType()->value, 'subject_id' => $object->getSubjectId(), 'created_at' => $at],
            // 0.10.0: another runner's reference of the same HubSpot object (its target: a record that is gone), and
            // another manager's first connection of the tenant.
            $object instanceof ExternalRef       => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'source' => $object->getSource(), 'account_id' => $object->getAccountId(), 'object_type' => $object->getObjectType(), 'external_id' => $object->getExternalId(), 'target_type' => $object->getTargetType(), 'target_id' => '01900000-0000-7000-8000-ffffffffffff', 'erased' => 0, 'created_at' => $at, 'updated_at' => $at],
            $object instanceof HubSpotConnection => ['id' => $id, 'tenant_id' => $object->getTenantId(), 'kind' => 'private_app', 'status' => 'active', 'account_id' => '1', 'label' => 'Racer', 'token_last4' => 'abcd', 'token_key_id' => 'k1', 'token_sealed' => 'AAAA', 'scopes' => '[]', 'scopes_verified' => 1, 'validated_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'version' => 1],
            // 0.13.0: another person's first classification of the same template.
            $object instanceof TemplatePurpose => ['id' => $object->getId(), 'tenant_id' => $object->getTenantId(), 'purpose' => null, 'version' => $object->getVersion(), 'updated_at' => $at],
            default                            => null,
        };
        if ($row !== null) {
            $em->getConnection()->insert($table, $row);
        }
    }
}
