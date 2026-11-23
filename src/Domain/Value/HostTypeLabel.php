<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectTypeLabelInterface;

use function mb_strlen;
use function preg_match;
use function trim;

/**
 * The human name of a host object type in the link form (0.7.0, {@see CrmHostObjectTypeLabelInterface}):
 * a translation key and its domain, or a plain text shown as it is.
 */
final readonly class HostTypeLabel
{
    /** Translation domain pattern (anchored, `/D`). */
    private const DOMAIN_PATTERN = '/\A[A-Za-z0-9_.+-]{1,100}\z/D';

    /**
     * @param string $text a translation key when `$domain` is set, else the text itself (1–200 characters)
     * @param string|null $domain the translation domain of the key; null: the text is shown untranslated
     */
    private function __construct(public string $text, public ?string $domain)
    {
        if (trim($text) === '' || mb_strlen($text) > 200) {
            throw new InvalidArgumentException('A host type label is 1-200 characters.');
        }
        if ($domain !== null && preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException('A translation domain is 1-100 characters of A-Z, a-z, 0-9, ".", "_", "+" or "-".');
        }
    }

    /**
     * A translation key of a domain (e.g. `crm.host.project` in `messages`).
     */
    public static function trans(string $key, string $domain = 'messages'): self
    {
        return new self($key, $domain);
    }

    /**
     * A text shown untranslated.
     */
    public static function text(string $text): self
    {
        return new self($text, null);
    }
}
