<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * What a stored e-mail template is (0.13.0, `templates.enabled`): a whole message (subject and body) or a snippet (a
 * body fragment inserted into a message). The rows live in MailKit; the values are MailKit's `TemplateKind`.
 */
enum EmailTemplateKind: string
{
    case Template = 'template';
    case Snippet  = 'snippet';
}
