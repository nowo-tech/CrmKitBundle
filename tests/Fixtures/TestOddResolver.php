<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectSearchInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectTypeLabelInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\HostTypeLabel;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A configurable resolver with both capabilities for the picker's edge cases (0.7.0): a second resolver of a type,
 * an invalid type, a label shared by two types, a translated label, objects without a label. Its search answers
 * every object whatever the text.
 */
final readonly class TestOddResolver implements CrmHostObjectResolverInterface, CrmHostObjectTypeLabelInterface, CrmHostObjectSearchInterface
{
    /**
     * @param array<string, string> $objects id => label (any tenant)
     */
    public function __construct(private string $type, private string $label, private ?string $domain = null, private array $objects = [])
    {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function typeLabel(): HostTypeLabel
    {
        return $this->domain === null ? HostTypeLabel::text($this->label) : HostTypeLabel::trans($this->label, $this->domain);
    }

    public function resolve(TenantRef $tenant, array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            if (isset($this->objects[$id])) {
                $found[$id] = new HostObjectView($id, $this->objects[$id]);
            }
        }

        return $found;
    }

    public function search(TenantRef $tenant, string $text, int $limit): array
    {
        $found = [];
        foreach ($this->objects as $id => $label) {
            $found[] = new HostObjectView((string) $id, $label);
        }

        return $found;
    }
}
