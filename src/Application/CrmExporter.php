<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;

use function array_map;
use function count;
use function implode;
use function in_array;
use function str_contains;
use function str_replace;

/**
 * Exports (contract section 9): CSV of every record matching a list's filters (with the configured role keys, the
 * format the import reads back) (keyset batches of 500, flat memory;
 * cells starting with `=`, `+`, `-`, `@`, tab or CR prefixed with `'`), and a vCard 4.0 of one contact. Stateless.
 */
final readonly class CrmExporter implements CrmExporterInterface
{
    /** Rows per batch. */
    private const BATCH = 500;

    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private CrmReader $reader, private RecordViews $views)
    {
    }

    /**
     * CSV lines.
     */
    public function csv(ListQuery $query): iterable
    {
        $context = $this->support->access->context(CrmPermission::Export);
        $fields  = $this->support->read(fn (): array => $this->support->store->fields($context->tenant, $query->type, false));
        $owners  = $this->support->access->visibleOwners($context);
        $keys    = array_map(static fn (FieldDefinition $f): string => $f->getFieldKey(), $fields);
        $header  = ['id', ...RecordFields::names($query->type), ...array_map(static fn (string $k): string => 'custom.' . $k, $keys), 'owner', 'roles', 'tags', 'created_at', 'updated_at'];

        yield self::line($header);
        $after = null;
        do {
            $views = $this->support->read(function () use ($context, $query, $owners, &$after): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $records = $this->support->store->batch($context->tenant, $query, $after, self::BATCH, $owners);
                $after   = $records === [] ? $after : $records[count($records) - 1]->getId();

                return $this->views->many($context->tenant, $query->type, $records);
            });
            foreach ($views as $view) {
                yield self::line($this->cells($view, $keys));
            }
        } while (count($views) === self::BATCH);
    }

    /**
     * A vCard 4.0.
     */
    public function vcard(string $contactId): string
    {
        $view = $this->reader->record(SubjectType::Contact, $contactId);
        $v    = $view->values;
        $org  = '';
        foreach ($view->affiliations as $affiliation) {
            if ($affiliation->primary || $org === '') {
                $org = $affiliation->companyName;
            }
        }
        $lines   = ['BEGIN:VCARD', 'VERSION:4.0'];
        $lines[] = 'FN:' . self::escape($view->label);
        $lines[] = 'N:' . self::escape($v['last_name'] ?? '') . ';' . self::escape($v['first_name'] ?? '') . ';;;';
        foreach (['email' => 'EMAIL', 'phone' => 'TEL;TYPE=work', 'mobile' => 'TEL;TYPE=cell', 'job_title' => 'TITLE'] as $field => $property) {
            if (($v[$field] ?? null) !== null) {
                $lines[] = $property . ':' . self::escape((string) $v[$field]);
            }
        }
        if ($org !== '') {
            $lines[] = 'ORG:' . self::escape($org);
        }
        if (($v['street'] ?? null) !== null || ($v['city'] ?? null) !== null) {
            $lines[] = 'ADR:;;' . implode(';', array_map(static fn (string $f): string => self::escape($v[$f] ?? ''), ['street', 'city', 'region', 'postal_code', 'country']));
        }
        $lines[] = 'UID:urn:uuid:' . $view->id;
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The cells of a record.
     *
     * @param list<string> $customKeys
     *
     * @return list<string>
     */
    private function cells(RecordView $view, array $customKeys): array
    {
        $cells = [$view->id];
        foreach ($view->values as $value) {
            $cells[] = $value ?? '';
        }
        foreach ($customKeys as $key) {
            $cells[] = $view->custom[$key] ?? '';
        }
        $cells[] = $view->ownerName ?? $view->ownerId ?? '';
        $cells[] = implode(', ', $view->roles);
        $cells[] = implode(', ', array_map(static fn (TagView $t): string => $t->name, $view->tags));
        $cells[] = $view->createdAt->format('Y-m-d H:i:s');
        $cells[] = $view->updatedAt->format('Y-m-d H:i:s');

        return $cells;
    }

    /**
     * One CSV line (RFC 4180 quoting, formula cells neutralised).
     *
     * @param list<string> $cells
     */
    public static function line(array $cells): string
    {
        return implode(',', array_map(static function (string $cell): string {
            if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $cell = "'" . $cell;
            }

            return '"' . str_replace('"', '""', $cell) . '"';
        }, $cells)) . "\r\n";
    }

    private static function escape(string $value): string
    {
        $value = str_replace(['\\', ',', ';', "\r\n", "\n", "\r"], ['\\\\', '\\,', '\;', '\\n', '\\n', '\\n'], $value);

        return str_contains($value, "\0") ? str_replace("\0", '', $value) : $value;
    }
}
