<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use JsonException;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use SensitiveParameter;
use SodiumException;

use function base64_decode;
use function base64_encode;
use function hash;
use function is_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rtrim;
use function sodium_crypto_kdf_derive_from_key;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function strlen;
use function strtr;
use function substr;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_KDF_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Sealed keyset cursors (0.3.0, design §7, QA-7): a base64url `sodium_crypto_secretbox` of
 * `{v: 1, op, f, k}` under a key derived from `kernel.secret` (context `crmapicr`). Sealing keeps the last row's
 * values out of URLs, logs and transcripts; a tampered cursor, one of another operation or filters, or one sealed under
 * a rotated secret is 400 `INPUT_003`. Stateless: the key is derived per call.
 */
final readonly class CrmApiCursorCodec
{
    /**
     * @param string $secret `kernel.secret`
     */
    public function __construct(#[SensitiveParameter] private string $secret)
    {
    }

    /**
     * A short hash of canonical filters.
     *
     * @param array<string, mixed> $filters
     */
    public static function filterHash(array $filters): string
    {
        return substr(hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * Seals the key of the last row of a page.
     *
     * @param array<string, mixed> $key
     */
    public function seal(CrmApiOperation $operation, string $filterHash, array $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox(json_encode(['v' => 1, 'op' => $operation->value, 'f' => $filterHash, 'k' => $key], JSON_THROW_ON_ERROR), $nonce, $this->key());

        return rtrim(strtr(base64_encode($nonce . $box), '+/', '-_'), '=');
    }

    /**
     * The key sealed in a cursor of this operation and filters.
     *
     * @throws CrmApiRefusal `INPUT_003`
     *
     * @return array<string, mixed>
     */
    public function open(CrmApiCursor $cursor, CrmApiOperation $operation, string $filterHash): array
    {
        $raw = base64_decode(strtr($cursor->value, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw CrmApiRefusal::of(CrmApiCode::INPUT_CURSOR);
        }
        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
            $data  = $plain === false ? null : json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException|SodiumException) {
            $data = null;
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || ($data['op'] ?? null) !== $operation->value || ($data['f'] ?? null) !== $filterHash || !is_array($data['k'] ?? null)) {
            throw CrmApiRefusal::of(CrmApiCode::INPUT_CURSOR);
        }
        $key = [];
        foreach ($data['k'] as $name => $value) {
            $key[(string) $name] = $value;
        }

        return $key;
    }

    private function key(): string
    {
        $master = substr(hash('sha256', 'nowo-crm-kit-cursor|' . $this->secret, true), 0, SODIUM_CRYPTO_KDF_KEYBYTES);

        return sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 1, 'crmapicr', $master);
    }
}
