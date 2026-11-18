<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use InvalidArgumentException;

/**
 * Input refused by the CRM's rules: field => translation key of the error (422 in JSON, form errors in HTML).
 */
final class InvalidInputException extends InvalidArgumentException implements CrmException
{
    /**
     * @param array<string, string> $errors field (or `_` for the whole input) => translation key
     * @param array<string, string> $params translation parameters
     */
    public function __construct(public readonly array $errors, public readonly array $params = [])
    {
        parent::__construct('Invalid input.');
    }

    /**
     * One error.
     *
     * @param array<string, string> $params
     */
    public static function field(string $field, string $key, array $params = []): self
    {
        return new self([$field => $key], $params);
    }
}
