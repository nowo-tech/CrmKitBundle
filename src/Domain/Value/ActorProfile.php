<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * A person of the host as the CRM shows them (owner pickers, note authors).
 */
final readonly class ActorProfile
{
    /**
     * @param ActorRef $ref the person
     * @param string $name display name
     * @param string|null $email e-mail, if the host shares it
     */
    public function __construct(public ActorRef $ref, public string $name, public ?string $email = null)
    {
    }
}
