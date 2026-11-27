<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Templates\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The CRM's data about stored e-mail templates and sends (0.13.0, `templates.enabled`): the consent classification per
 * MailKit template id, the per-person send window and the one-time send keys of *Write e-mail*. Tenant-scoped; ids
 * compared exactly. The write methods other than {@see self::forget()} and {@see self::release()} run inside the
 * caller's write unit.
 */
interface TemplateMailStoreInterface
{
    /**
     * The classifications of the given templates: id ⇒ consent purpose, or null for a service message; a template
     * without a classification is absent.
     *
     * @param list<string> $ids
     *
     * @return array<string, string|null>
     */
    public function classifications(TenantRef $tenant, array $ids): array;

    /**
     * Sets a template's classification (inside a write unit).
     */
    public function classify(TenantRef $tenant, string $id, ?string $purpose, DateTimeImmutable $now): void;

    /**
     * Deletes a template's classification at once (one statement: inside MailKit's delete transaction when both share
     * the connection, else on its own).
     */
    public function forget(string $tenant, string $id): void;

    /**
     * Counts one send of a person in a fixed window (inside a write unit); false once `$limit` sends were counted.
     */
    public function takeSend(TenantRef $tenant, string $actorId, DateTimeImmutable $now, int $limit, int $interval): bool;

    /**
     * Claims a send key (inside a write unit); false when it was claimed already. Claims older than a day are dropped.
     */
    public function claim(TenantRef $tenant, string $key, DateTimeImmutable $now): bool;

    /**
     * Releases a claimed key (a send that failed), in a unit of its own.
     */
    public function release(TenantRef $tenant, string $key): void;
}
