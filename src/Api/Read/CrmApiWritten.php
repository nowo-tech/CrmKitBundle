<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Read;

use JsonSerializable;

use function is_array;

/**
 * The answer of a write (0.3.0): the result, whether it is a replay of an earlier call with the same key, whether the
 * write changed anything, and the HTTP status of the first answer (201 stays 201 on a replay).
 */
final readonly class CrmApiWritten implements JsonSerializable
{
    /**
     * Wires the answer.
     */
    public function __construct(public JsonSerializable $result, public bool $replayed, public bool $changed, public int $status = 200)
    {
    }

    /**
     * The result's JSON with `replayed` and `changed`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = $this->result->jsonSerialize();
        $out  = [];
        foreach (is_array($data) ? $data : ['result' => $data] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out + ['replayed' => $this->replayed, 'changed' => $this->changed];
    }
}
