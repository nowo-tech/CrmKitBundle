<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;

/**
 * MailKit's template permission port on the CRM's access gate (0.13.0; point `nowo_mail_kit.templates.permission` at
 * `nowo_crm_kit.template_permission`): a MailKit actor that is not exactly the call's CRM tenant and person is refused
 * (deny by default; MailKit contract C3), then personal templates and the use of shared ones need `edit` (the CRM
 * members who write to contacts) and managing shared ones needs `manage`, tenant-wide, through the host's access
 * checker and its events. Stateless.
 */
final readonly class CrmTemplatePermission implements TemplatePermissionInterface
{
    /**
     * Wires the actors.
     */
    public function __construct(private CrmTemplateActors $actors)
    {
    }

    /**
     * Whether the actor holds the template permission.
     */
    public function isGranted(TemplatePermission $permission, TemplateActor $actor): bool
    {
        $context = $this->actors->matching($actor);
        if (!$context instanceof CrmContext) {
            return false;
        }
        try {
            $this->actors->access()->require($context, $permission === TemplatePermission::ManageShared ? CrmPermission::Manage : CrmPermission::Edit);
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }
}
