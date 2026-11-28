<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;

use function in_array;
use function is_string;
use function trim;

/**
 * The common part of the CRM's placeholder providers (0.13.0): the definitions from a fixed name ⇒ label-key map, the
 * actor check (exactly the call's CRM tenant and person, else nothing resolves) and the subject id of the context.
 * Values are only those the record's view shows the person; anything the person may not see, or that does not exist,
 * is left out (MailKit keeps the placeholder as literal text). Stateless (worker mode).
 *
 * @internal
 */
abstract class CrmPlaceholders implements PlaceholderProviderInterface
{
    /**
     * Wires the actors.
     */
    public function __construct(protected readonly CrmTemplateActors $actors)
    {
    }

    /**
     * The names and their label keys (`NowoCrmKitBundle` translation domain).
     *
     * @return array<string, string>
     */
    abstract public static function names(): array;

    /**
     * The values of the wanted names for the call (only names of {@see self::names()}).
     *
     * @param list<string> $names
     *
     * @return array<string, string|null>
     */
    abstract protected function values(CrmContext $context, PlaceholderContext $placeholders, array $names): array;

    /**
     * The placeholders an editor may offer; the labels are `NowoCrmKitBundle` translation keys.
     *
     * @return list<PlaceholderDefinition>
     */
    public function definitions(): array
    {
        $out = [];
        foreach (static::names() as $name => $label) {
            $out[] = new PlaceholderDefinition($name, $label);
        }

        return $out;
    }

    /**
     * The values of the names the call may see.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    public function resolve(array $names, PlaceholderContext $context): array
    {
        $crm = $this->actors->matching($context->actor);
        if (!$crm instanceof CrmContext) {
            return [];
        }
        $known = [];
        foreach ($names as $name) {
            if (isset(static::names()[$name]) && !in_array($name, $known, true)) {
                $known[] = $name;
            }
        }
        if ($known === []) {
            return [];
        }
        try {
            $values = $this->values($crm, $context, $known);
        } catch (NotAvailableException|AccessDeniedException) {
            return [];
        }
        $out = [];
        foreach ($known as $name) {
            $value = $values[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * The record id named by the context for a subject, or null.
     */
    protected static function subjectId(PlaceholderContext $context, string $subject): ?string
    {
        $id = $context->subject($subject);

        return is_string($id) && RecordId::isValid($id) ? $id : null;
    }
}
