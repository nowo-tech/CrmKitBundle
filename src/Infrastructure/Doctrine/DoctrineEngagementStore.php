<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\CrmKitBundle\Application\Engagement\Port\EngagementStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementAttendee;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementSubject;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Type\UtcDateTimeImmutableType;

use function array_map;
use function hash;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function substr;

/**
 * {@see EngagementStoreInterface} on the bundle's entity manager (0.11.0). Every query names the tenant and every
 * result is re-checked with `===` against it (REQ-SEC-006 rule 2); id lists are compared exactly too. The feed is a
 * keyset page (time, then id, newest first) through the subject rows. Stateless: the entity manager comes from the
 * runner on every call.
 *
 * @internal
 */
final readonly class DoctrineEngagementStore implements EngagementStoreInterface
{
    /**
     * Wires the runner.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function engagement(TenantRef $tenant, string $id): ?Engagement
    {
        $query = $this->em()->createQueryBuilder()->select('e')->from(Engagement::class, 'e')
            ->where('e.id = :id')->andWhere('e.tenantId = :tenant')
            ->setParameter('id', $id)->setParameter('tenant', $tenant->value)->getQuery();
        foreach ($this->exact($query->getResult(), Engagement::class, $tenant) as $row) {
            if ($row->getId() === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function engagements(TenantRef $tenant, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('e')->from(Engagement::class, 'e')
            ->where('e.tenantId = :tenant')->andWhere('e.id IN (:ids)')
            ->setParameter('tenant', $tenant->value)->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->orderBy('e.occurredAt', 'ASC')->addOrderBy('e.id', 'ASC')->getQuery()->getResult();
        $out = [];
        foreach ($this->exact($rows, Engagement::class, $tenant) as $row) {
            if (in_array($row->getId(), $ids, true)) {
                $out[$row->getId()] = $row;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function feed(TenantRef $tenant, SubjectType $type, string $id, array $kinds, ?string $author, ?DateTimeImmutable $before, ?string $beforeId, int $limit): array
    {
        $qb = $this->em()->createQueryBuilder()->select('e')->from(Engagement::class, 'e')
            ->innerJoin(EngagementSubject::class, 's', 'WITH', 's.engagementId = e.id AND s.tenantId = :tenant')
            ->where('e.tenantId = :tenant')->andWhere('s.subjectType = :type')->andWhere('s.subjectId = :subject')
            ->andWhere('e.kind IN (:kinds)')
            ->setParameter('tenant', $tenant->value)->setParameter('type', $type)->setParameter('subject', $id)
            ->setParameter('kinds', array_map(static fn (ActivityKind $k): string => $k->value, $kinds === [] ? ActivityKind::LOGGABLE : $kinds), ArrayParameterType::STRING)
            ->orderBy('e.occurredAt', 'DESC')->addOrderBy('e.id', 'DESC')->setMaxResults($limit);
        if ($author !== null) {
            $qb->andWhere('e.authorId = :author')->setParameter('author', $author); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($before instanceof DateTimeImmutable) {
            $qb->andWhere($beforeId === null ? 'e.occurredAt < :before' : '(e.occurredAt < :before OR (e.occurredAt = :before AND e.id < :before_id))') // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                ->setParameter('before', $before, UtcDateTimeImmutableType::NAME);
            if ($beforeId !== null) {
                $qb->setParameter('before_id', $beforeId); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }

        return $this->exact($qb->getQuery()->getResult(), Engagement::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function ofPrimary(TenantRef $tenant, SubjectType $type, string $id): array
    {
        $rows = $this->em()->getRepository(Engagement::class)->findBy(['tenantId' => $tenant->value, 'subjectType' => $type, 'subjectId' => $id], ['occurredAt' => 'ASC', 'id' => 'ASC']);
        $out  = [];
        foreach ($this->exact($rows, Engagement::class, $tenant) as $row) {
            if ($row->getSubjectId() === $id) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function subjectsOf(TenantRef $tenant, array $engagementIds): array
    {
        if ($engagementIds === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('s')->from(EngagementSubject::class, 's')
            ->where('s.tenantId = :tenant')->andWhere('s.engagementId IN (:ids)')
            ->setParameter('tenant', $tenant->value)->setParameter('ids', $engagementIds, ArrayParameterType::STRING)
            ->orderBy('s.id')->getQuery()->getResult();
        $out = [];
        foreach ($this->exact($rows, EngagementSubject::class, $tenant) as $row) {
            if (in_array($row->getEngagementId(), $engagementIds, true)) {
                $out[$row->getEngagementId()][] = $row;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function subjectRows(TenantRef $tenant, SubjectType $type, string $id): array
    {
        $rows = $this->em()->getRepository(EngagementSubject::class)->findBy(['tenantId' => $tenant->value, 'subjectType' => $type, 'subjectId' => $id], ['id' => 'ASC']);
        $out  = [];
        foreach ($this->exact($rows, EngagementSubject::class, $tenant) as $row) {
            if ($row->getSubjectId() === $id) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function attendeesOf(TenantRef $tenant, array $engagementIds): array
    {
        if ($engagementIds === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()->select('a')->from(EngagementAttendee::class, 'a')
            ->where('a.tenantId = :tenant')->andWhere('a.engagementId IN (:ids)')
            ->setParameter('tenant', $tenant->value)->setParameter('ids', $engagementIds, ArrayParameterType::STRING)
            ->orderBy('a.id')->getQuery()->getResult();
        $out = [];
        foreach ($this->exact($rows, EngagementAttendee::class, $tenant) as $row) {
            if (in_array($row->getEngagementId(), $engagementIds, true)) {
                $out[$row->getEngagementId()][] = $row;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function attendeeRowsOfContact(TenantRef $tenant, string $contactId): array
    {
        $rows = $this->em()->getRepository(EngagementAttendee::class)->findBy(['tenantId' => $tenant->value, 'contactId' => $contactId], ['id' => 'ASC']);
        $out  = [];
        foreach ($this->exact($rows, EngagementAttendee::class, $tenant) as $row) {
            if ($row->getContactId() === $contactId) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function meetingsOf(TenantRef $tenant, string $actorId, DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        $attending = $this->em()->createQueryBuilder()->select('a.engagementId')->from(EngagementAttendee::class, 'a')
            ->where('a.tenantId = :tenant')->andWhere('a.actorId = :actor')->getDQL();
        $rows = $this->em()->createQueryBuilder()->select('e')->from(Engagement::class, 'e')
            ->where('e.tenantId = :tenant')->andWhere('e.kind = :meeting')->andWhere('e.cancelled = :cancelled')
            ->andWhere('e.occurredAt < :to')->andWhere('e.endsAt > :from')
            ->andWhere('e.authorId = :actor OR e.id IN (' . $attending . ')')
            ->setParameter('tenant', $tenant->value)->setParameter('meeting', ActivityKind::Meeting)->setParameter('cancelled', false)
            ->setParameter('actor', $actorId)
            ->setParameter('from', $from, UtcDateTimeImmutableType::NAME)->setParameter('to', $to, UtcDateTimeImmutableType::NAME)
            ->orderBy('e.occurredAt', 'ASC')->addOrderBy('e.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

        return $this->exact($rows, Engagement::class, $tenant);
    }

    /**
     * {@inheritDoc}
     */
    public function takeMentionCall(TenantRef $tenant, string $actorId, DateTimeImmutable $now, int $limit, int $interval): bool
    {
        // One counter pair per person (a hash of the id: the counter key holds 32 characters).
        $key = substr(hash('sha256', $actorId), 0, 24);

        return (new DoctrineCounterWindow($this->runner))->take($tenant, $now, $limit, $interval, 'mw_' . $key, 'mc_' . $key);
    }

    /**
     * {@inheritDoc}
     */
    public function countBefore(TenantRef $tenant, DateTimeImmutable $before): int
    {
        $count = $this->em()->createQueryBuilder()->select('COUNT(e.id)')->from(Engagement::class, 'e')
            ->where('e.tenantId = :tenant')->andWhere('e.occurredAt < :before')
            ->setParameter('tenant', $tenant->value)->setParameter('before', $before, UtcDateTimeImmutableType::NAME)
            ->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int
    {
        $em       = $this->em();
        $db       = $em->getConnection();
        $table    = $em->getClassMetadata(Engagement::class)->getTableName();
        $subjects = $em->getClassMetadata(EngagementSubject::class)->getTableName();
        $people   = $em->getClassMetadata(EngagementAttendee::class)->getTableName();
        $rows     = $db->fetchAllAssociative(
            $db->getDatabasePlatform()->modifyLimitQuery('SELECT id, tenant_id FROM ' . $table . ' WHERE tenant_id = ? AND occurred_at < ? ORDER BY id', $limit),
            [$tenant->value, $before],
            [ParameterType::STRING, UtcDateTimeImmutableType::NAME],
        );
        $ids = [];
        foreach ($rows as $row) {
            if ($row['tenant_id'] === $tenant->value && is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }
        if ($ids === []) {
            return 0;
        }
        foreach ([$subjects, $people] as $child) {
            $db->executeStatement('DELETE FROM ' . $child . ' WHERE tenant_id = ? AND engagement_id IN (?)', [$tenant->value, $ids], [ParameterType::STRING, ArrayParameterType::STRING]);
        }

        // The rows really deleted (another run may have taken some meanwhile).
        return (int) $db->executeStatement('DELETE FROM ' . $table . ' WHERE tenant_id = ? AND id IN (?)', [$tenant->value, $ids], [ParameterType::STRING, ArrayParameterType::STRING]);
    }

    /**
     * The rows of the class whose tenant matches byte for byte.
     *
     * @template T of Engagement|EngagementSubject|EngagementAttendee
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function exact(mixed $rows, string $class, TenantRef $tenant): array
    {
        $kept = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($row instanceof $class && $row->getTenantId() === $tenant->value) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    private function em(): EntityManagerInterface
    {
        return $this->runner->manager();
    }
}
