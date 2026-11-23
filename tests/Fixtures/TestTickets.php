<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectSearchInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectTypeLabelInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\HostTypeLabel;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_slice;
use function str_contains;

/**
 * A host resolver with both optional capabilities (0.7.0): type `ticket`, label "Support ticket", a search by label.
 * `t-1`, `t-2` in `acme`, `t-9` in `beta`. Its search misbehaves on purpose: it also answers an unknown id, a
 * look-alike id and, for "leak", another tenant's ticket; the picker must drop them all. Registered only by the
 * picker tests (the other tests keep a host without capabilities).
 */
final readonly class TestTickets implements CrmHostObjectResolverInterface, CrmHostObjectTypeLabelInterface, CrmHostObjectSearchInterface
{
    public const TICKETS = ['acme' => ['t-1' => 'Printer broken', 't-2' => 'Printer toner', 't-3' => 'VPN down'], 'beta' => ['t-9' => 'Printer beta']];

    public function __construct(private TestContext $context)
    {
    }

    public function type(): string
    {
        return 'ticket';
    }

    public function typeLabel(): HostTypeLabel
    {
        return HostTypeLabel::text('Support ticket');
    }

    public function resolve(TenantRef $tenant, array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $label = self::TICKETS[$tenant->value][$id] ?? null;
            if ($label !== null) {
                $found[$id] = new HostObjectView($id, $label, '/tickets/' . $id);
            }
        }

        return $found;
    }

    public function search(TenantRef $tenant, string $text, int $limit): array
    {
        $this->context->ticketSearches[] = [$tenant->value, $text, $limit];
        if ($text === 'leak') {
            return [new HostObjectView('t-9', 'Printer beta')];
        }
        $found = [];
        foreach (self::TICKETS[$tenant->value] ?? [] as $id => $label) {
            if (str_contains($label, $text)) {
                $found[] = new HostObjectView($id, $label);
            }
        }
        $found[] = new HostObjectView('ghost', 'Printer ghost');
        $found[] = new HostObjectView('T-1', 'Printer look-alike');

        return array_slice($found, 0, $limit + 2);
    }
}
