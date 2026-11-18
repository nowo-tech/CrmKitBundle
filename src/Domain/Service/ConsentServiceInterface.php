<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;

/**
 * Consent per contact and purpose (contract section 16.1), append-only.
 */
interface ConsentServiceInterface
{
    /**
     * Records a decision (`edit`); the consent row id.
     */
    public function record(string $contactId, string $purpose, bool $granted, ConsentSource $source, ?string $evidence = null): string;

    /**
     * The current state per purpose (true granted, false withdrawn; a purpose never recorded is absent), `view`.
     *
     * @return array<string, bool>
     */
    public function current(string $contactId): array;
}
