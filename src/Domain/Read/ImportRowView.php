<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;

/**
 * A row of an import preview.
 */
final readonly class ImportRowView
{
    /**
     * @param array<string, string> $data column => cell
     * @param string|null $message error translation key
     * @param array<string, string> $errors field => error key (preview validation)
     * @param list<string> $duplicates labels of possible duplicates
     */
    public function __construct(
        public int $rowNo,
        public array $data,
        public RowOutcome $outcome,
        public ?string $recordId,
        public ?string $message,
        public array $errors,
        public array $duplicates,
    ) {
    }
}
