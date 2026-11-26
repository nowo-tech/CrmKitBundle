<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * What a logged e-mail said, as plain text (0.11.0): at most 500 characters, shown escaped. Never the full message: a
 * person logs the gist (the mailbox sync of block B stores message references, not bodies).
 */
final readonly class EmailExcerpt
{
    /** Longest excerpt, in characters. */
    public const MAX = 500;

    public string $value;

    /**
     * @throws InvalidArgumentException empty, too long or with control characters other than line breaks and tabs
     */
    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::MAX || preg_match('/[^\P{Cc}\t\n\r]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1) {
            throw new InvalidArgumentException('An e-mail excerpt is 1 to 500 characters of plain text.');
        }
        $this->value = $value;
    }
}
