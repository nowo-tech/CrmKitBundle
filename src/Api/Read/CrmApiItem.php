<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Read;

use JsonSerializable;

/**
 * A read model of the API (0.3.0): **facts** (ids, enums, keys, numbers, instants, flags) and **text** (every string
 * a person, an import or an agent wrote: untrusted) are kept apart, so a presenter cannot mix them by accident. The
 * JSON is `{...facts, "text": {...}}`; the OpenAPI marks `text` `x-untrusted: true`.
 *
 * @phpstan-type Facts array<string, mixed>
 * @phpstan-type Text array<string, mixed>
 */
final readonly class CrmApiItem implements JsonSerializable
{
    /**
     * @param string $kind the schema name (`record`, `deal`, `note`, …)
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $text untrusted strings (possibly nested)
     */
    public function __construct(public string $kind, public array $facts, public array $text = [])
    {
    }

    /**
     * A fact, or null.
     */
    public function fact(string $key): mixed
    {
        return $this->facts[$key] ?? null;
    }

    /**
     * `{...facts, text}`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->facts + ['text' => (object) $this->text];
    }
}
