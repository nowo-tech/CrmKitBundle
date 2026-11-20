<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Support;

use Nowo\CrmKitBundle\Api\CrmApiOperation;

use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The shipped contract of the API's operations (`tests/Contract/crm-api-v1.json`), generated from
 * {@see CrmApiOperation}: an MCP host mirrors it (tool names, hints, required inputs, capability, refusal codes).
 */
final class CrmApiContract
{
    public const FILE = __DIR__ . '/../Contract/crm-api-v1.json';

    /**
     * The contract as data.
     *
     * @return array{version: int, operations: list<array<string, mixed>>}
     */
    public static function build(): array
    {
        $operations = [];
        foreach (CrmApiOperation::cases() as $operation) {
            $operations[] = [
                'operation'       => $operation->value,
                'tool'            => $operation->toolName(),
                'capability'      => $operation->capability()->value,
                'kind'            => $operation->kind(),
                'idempotent'      => $operation->idempotent(),
                'idempotency_key' => $operation->isWrite() ? 'required' : 'none',
                'base_version'    => $operation->needsBaseVersion(),
                'ticks'           => $operation->ticks(),
                'pipeline'        => $operation->pipeline(),
                'paged'           => $operation->paged(),
                'codes'           => $operation->codes(),
                'since'           => $operation->since(),
            ];
        }

        return ['version' => 1, 'operations' => $operations];
    }

    /**
     * The contract file's expected content.
     */
    public static function json(): string
    {
        return json_encode(self::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
