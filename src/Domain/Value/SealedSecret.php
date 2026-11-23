<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;
use function strlen;

/**
 * A sealed secret as stored (0.10.0): the id of the key that sealed it and the ciphertext (base64, at most 4 KiB).
 */
final readonly class SealedSecret
{
    /** Pattern of a key id (anchored, `/D`). */
    public const KEY_ID_PATTERN = '/\A[a-z0-9_-]{1,16}\z/D';

    /** Longest ciphertext in bytes. */
    public const MAX_BYTES = 4096;

    /**
     * @param string $keyId the sealing key's id
     * @param string $ciphertext base64 of the box's output
     */
    public function __construct(public string $keyId, public string $ciphertext)
    {
        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw new InvalidArgumentException('A key id is 1-16 characters of a-z, 0-9, "_" or "-".');
        }
        if ($ciphertext === '' || strlen($ciphertext) > self::MAX_BYTES || preg_match('#\A[A-Za-z0-9+/]+={0,2}\z#D', $ciphertext) !== 1) {
            throw new InvalidArgumentException('A sealed secret is base64 of at most 4 KiB.');
        }
    }
}
