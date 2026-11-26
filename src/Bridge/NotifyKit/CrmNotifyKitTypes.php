<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\NotifyKit;

use Nowo\CrmKitBundle\Domain\TranslationDomain;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\NotifyKitBundle\Contract\CategoryDefinition;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;

/**
 * The CRM's NotifyKit types (0.11.0): `crm.mention` in the category `crm`, personal, in-app on by default, the title
 * `notify.mention.title` of the bundle's translation domain.
 */
final readonly class CrmNotifyKitTypes implements TypeProviderInterface
{
    /** The category of the CRM's types. */
    public const CATEGORY = 'crm';

    /**
     * The types.
     *
     * @return list<TypeDefinition>
     */
    public function types(): iterable
    {
        return [new TypeDefinition(CrmNotification::MENTION, self::CATEGORY, 'tabler:at', 'notify.mention.title', null, [TypeDefinition::IN_APP => true], titleDomain: TranslationDomain::NAME)];
    }

    /**
     * The categories.
     *
     * @return list<CategoryDefinition>
     */
    public function categories(): iterable
    {
        return [new CategoryDefinition(self::CATEGORY, 'notify.category.crm', 'crm')];
    }
}
