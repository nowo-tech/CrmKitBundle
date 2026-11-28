<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A template or snippet rendered for one contact (0.13.0, *Write e-mail*): the subject (empty for a snippet) and the
 * text with its placeholders replaced by plain-text values the person may see. Placeholders without a value stay as
 * their literal text and are listed in `unresolved` (`contact.first_name`, …).
 */
final readonly class ComposedText
{
    /**
     * @param list<string> $unresolved
     */
    public function __construct(public string $subject, public string $text, public array $unresolved = [])
    {
    }
}
