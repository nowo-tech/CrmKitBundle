<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use Generator;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

use function is_string;
use function preg_match;
use function substr;

/**
 * A HubSpot bearer credential in clear (0.10.0): a service key, or a legacy private app token. Its kind is never told
 * from a prefix and no format is refused by shape: the only rule is what an HTTP header can carry safely (8–4096
 * visible ASCII characters, no space or control character, so no header injection). HubSpot decides whether it is
 * valid. Lives for one call only: never stored in a property of a service, never logged, never serialised.
 *
 * The plaintext is not a property value (audit ZAM-2): it lives in the local frame of a suspended generator, which no
 * dumper can read, so `var_dump()`, `print_r()`, Symfony's VarDumper (`dump()`, the profiler, Twig's `dump`),
 * `(array)` casts and exception traces show no value. It cannot be cloned or serialised.
 */
final readonly class HubSpotToken
{
    /** The pattern (anchored, `/D`). */
    public const PATTERN = '/\A[\x21-\x7E]{8,4096}\z/D';

    /** Yields the plaintext from its own frame (a generator's locals are not dumpable). */
    private Generator $value;

    /**
     * @param string $value the token as pasted
     */
    public function __construct(#[SensitiveParameter] string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('A HubSpot credential is 8-4096 visible ASCII characters without spaces.');
        }
        $this->value = (static function (#[SensitiveParameter] string $plaintext): Generator {
            while (true) {
                yield $plaintext;
            }
        })($value);
    }

    /**
     * Never cloned (a clone would share the plaintext's lifetime).
     */
    public function __clone()
    {
        throw new LogicException('A HubSpot token is never cloned.');
    }

    /**
     * Whether the value can be sent as a bearer header.
     */
    public static function isValid(#[SensitiveParameter] string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The token in clear (the `Authorization: Bearer` header, the secret box).
     */
    public function reveal(): string
    {
        $plaintext = $this->value->current();

        return is_string($plaintext) ? $plaintext : '';
    }

    /**
     * Its last four characters, the only part ever shown.
     */
    public function last4(): string
    {
        return substr($this->reveal(), -4);
    }

    /**
     * No value in dumps.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * Never serialised.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A HubSpot token is never serialised.');
    }

    /**
     * Never unserialised.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A HubSpot token is never unserialised.');
    }
}
