<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\MailKitBundle\Template\Value\TemplateActor;

/**
 * The bridge between the CRM's caller and MailKit's caller-passed {@see TemplateActor} (0.13.0): the actor of a call
 * is the CRM's tenant and person, and a MailKit actor is accepted (by the permission port and the placeholder
 * providers) only when it is exactly the call's (MailKit never derives them, so this check is the host's job: MailKit
 * contract C3). Stateless.
 *
 * @internal
 */
final readonly class CrmTemplateActors
{
    /**
     * Wires the gate.
     */
    public function __construct(private CrmAccess $access)
    {
    }

    /**
     * The MailKit actor of the call.
     *
     * @throws NotAvailableException no CRM for the call, or a tenant / person reference MailKit does not accept
     */
    public function actor(CrmContext $context): TemplateActor
    {
        try {
            return new TemplateActor($context->tenant->value, $context->actor->value);
        } catch (InvalidArgumentException) {
            throw new NotAvailableException();
        }
    }

    /**
     * The CRM context of the call when `$actor` is exactly its tenant and person, else null.
     */
    public function matching(TemplateActor $actor): ?CrmContext
    {
        try {
            $context = $this->access->base();
        } catch (NotAvailableException) {
            return null;
        }

        return $context->tenant->value === $actor->tenant && $context->actor->value === $actor->owner ? $context : null;
    }

    /**
     * The gate.
     */
    public function access(): CrmAccess
    {
        return $this->access;
    }
}
