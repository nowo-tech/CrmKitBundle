<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * What a mail sender tells about a message it accepted (0.13.0): the transport's message id, when it has one.
 */
final readonly class CrmSentMailRef
{
    /**
     * @param string|null $messageId the message id the transport gave, or null
     */
    public function __construct(public ?string $messageId = null)
    {
    }
}
