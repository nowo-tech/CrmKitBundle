<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A task that carries a next action (0.3.0): its type (`crm` for the built-in list, `taskboard`, …) and its id in
 * that system.
 */
final readonly class CrmTaskRef
{
    /** Shape of a task type. */
    public const TYPE_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /**
     * @throws InvalidArgumentException when the type or the id is malformed
     */
    public function __construct(public string $type, public string $id)
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1 || !ExactId::isValid($id)) {
            throw new InvalidArgumentException('Invalid task reference.');
        }
    }

    /**
     * `<type>:<id>`.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }
}
