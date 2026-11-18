<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\Shared\WriteConflictException;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\ImportCompleted;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Import\CsvParser;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Domain\Import\VcardParser;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\ImportRow;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Read\ImportRowView;
use Nowo\CrmKitBundle\Domain\Read\ImportView;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;

use function array_filter;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function json_encode;
use function mb_strlen;
use function mb_strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * CSV and vCard imports (contract sections 9 and 15.4): upload parses into rows; confirm claims the import with a
 * conditional UPDATE (one winner); run settles pending rows in chunks, one transaction each, holding the import row
 * lock for the chunk so two runners never process the same rows; a stale claim is taken over. Stateless.
 */
final readonly class ImportService implements ImportServiceInterface
{
    /** Actor of rows run without a signed-in person (resume command) when the uploader is unknown. */
    public const SYSTEM_ACTOR = '__crm_import__';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WriteSupport $support,
        private PlanCounter $counter,
        private ClockInterface $clock,
        private int $maxRows = 5000,
        private int $maxBytes = 5242880,
        private int $chunkRows = 100,
        private int $staleSeconds = 600,
    ) {
    }

    /**
     * Parses and stores a file.
     */
    public function upload(SubjectType $type, ImportFormat $format, string $fileName, string $bytes): string
    {
        $context = $this->support->access->context(CrmPermission::Edit);
        $name    = trim($fileName) === '' || mb_strlen($fileName) > 255 || !ExactId::isValid(trim($fileName), 1020) ? 'import' : trim($fileName);
        if ($format === ImportFormat::Vcard && $type !== SubjectType::Contact) {
            throw new ImportFileException('import.error.vcard_contacts');
        }
        $file = $format === ImportFormat::Csv
            ? CsvParser::parse($bytes, $this->maxRows, $this->maxBytes)
            : VcardParser::parse($bytes, $this->maxRows, $this->maxBytes);

        return $this->support->write(function (Effects $effects) use ($context, $type, $format, $name, $file): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $fields  = $this->activeFields($context->tenant, $type);
            $mapping = ImportMapping::detect($file->columns, ImportMapping::targets($type, $fields));
            $id      = $this->support->id();
            $this->support->store->add(new Import($id, $context->tenant->value, $type, $format, $name, $file->columns, $mapping, count($file->rows), $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            foreach ($file->rows as $i => $row) {
                $this->support->store->add(new ImportRow($this->support->id(), $id, $context->tenant->value, $i + 1, $row)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            $this->support->audit($effects, $context, 'crm.import.uploaded', $type, $id, ['rows' => count($file->rows)]);

            return $id;
        });
    }

    /**
     * The import with its first rows checked.
     */
    public function preview(string $id, int $rows = 20): ImportView
    {
        $context = $this->support->access->context(CrmPermission::Edit);

        return $this->support->read(function () use ($context, $id, $rows): ImportView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import = $this->import($context->tenant, $id);
            $fields = $this->activeFields($context->tenant, $import->getSubjectType());
            $views  = [];
            foreach ($this->support->store->importRows($id, max(1, min(100, $rows)), false) as $row) {
                $errors     = [];
                $duplicates = [];
                [$input]    = ImportMapping::input($import->getSubjectType(), $row->getData(), $import->getMapping());
                try {
                    $values = RecordValidator::values($import->getSubjectType(), $input->values, $import->getSubjectType() === SubjectType::Company);
                    if ($import->getSubjectType() === SubjectType::Contact) {
                        RecordValidator::contactName($values);
                        $email    = $values['email'] ?? null;
                        $existing = $email === null ? null : $this->support->store->contactByEmailKey($context->tenant, mb_strtolower($email, 'UTF-8'));
                        if ($existing instanceof CrmRecord) {
                            $duplicates[] = $existing->label();
                        }
                    }
                    RecordValidator::custom($input->custom ?? [], $fields, []);
                } catch (InvalidInputException $e) {
                    $errors = $e->errors;
                }
                $views[] = new ImportRowView($row->getRowNo(), $row->getData(), $row->getOutcome(), $row->getRecordId(), $row->getMessage(), $errors, $duplicates);
            }

            return new ImportView(
                $import->getId(),
                $import->getSubjectType(),
                $import->getFormat(),
                $import->getStatus(),
                $import->getFileName(),
                $import->getColumns(),
                $import->getMapping(),
                $import->getDuplicatePolicy(),
                $import->getRowsTotal(),
                $import->getRowsDone(),
                $import->counts(),
                $views,
                $import->getVersion(),
                $import->getCreatedById(),
                $import->getCreatedAt(),
                $import->getClaimedAt(),
            );
        });
    }

    /**
     * Claims the import.
     */
    public function confirm(string $id, array $mapping, DuplicatePolicy $policy, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Edit);
        $won     = $this->support->write(function (Effects $effects) use ($context, $id, $mapping, $policy, $expectedVersion): bool { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import = $this->import($context->tenant, $id);
            if (!ImportMapping::valid($mapping, $import->getColumns(), ImportMapping::targets($import->getSubjectType(), $this->activeFields($context->tenant, $import->getSubjectType())))) {
                throw InvalidInputException::field('mapping', 'import.error.mapping');
            }
            $token = $this->support->id();

            return $this->support->store->claimImport($context->tenant, $id, $expectedVersion, $token, json_encode($mapping, JSON_THROW_ON_ERROR), $policy->value, $context->now);
        });
        if (!$won) {
            throw new ConflictException(ConflictException::IMPORT_RUNNING, $id);
        }
    }

    /**
     * Runs chunks.
     */
    public function run(string $id, ?int $maxChunks = null): bool
    {
        $context = $this->support->access->context(CrmPermission::Edit);
        $import  = $this->support->read(fn (): Import => $this->import($context->tenant, $id));
        if ($import->getStatus() !== ImportStatus::Running) {
            return $import->getStatus() === ImportStatus::Done;
        }

        return $this->runClaimed($context->tenant, $id, (string) $import->getClaimToken(), $maxChunks);
    }

    /**
     * Cancels an import.
     */
    public function cancel(string $id): void
    {
        $context = $this->support->access->context(CrmPermission::Edit);
        $this->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import = $this->import($context->tenant, $id);
            $stale  = $this->staleBefore($context->now);
            $from   = $import->getStatus() === ImportStatus::Running && $import->getClaimedAt() < $stale ? ImportStatus::Running : ImportStatus::Preview;
            if (!$this->support->store->finishImport($id, ImportStatus::Cancelled, $context->now, $from)) {
                throw new ConflictException(ConflictException::IMPORT_RUNNING, $id);
            }
            $this->support->audit($effects, $context, 'crm.import.cancelled', $import->getSubjectType(), $id);
        });
    }

    /**
     * Takes over the imports whose claim is stale and runs them (resume command, no signed-in person); how many
     * finished.
     */
    public function resumeStale(int $limit = 20): int
    {
        $now      = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $finished = 0;
        foreach ($this->support->read(fn (): array => $this->support->store->staleImports($this->staleBefore($now), $limit)) as $stale) {
            $tenant = new TenantRef($stale['tenant']);
            $token  = $this->support->id();
            $won    = $this->support->write(fn (): bool => $this->support->store->takeOverImport($tenant, $stale['id'], $token, $now, $this->staleBefore($now)));
            if ($won && $this->runClaimed($tenant, $stale['id'], $token, null)) {
                ++$finished;
            }
        }

        return $finished;
    }

    /**
     * Runs chunks while this claim holds the import.
     */
    private function runClaimed(TenantRef $tenant, string $id, string $token, ?int $maxChunks): bool
    {
        for ($chunk = 0; $maxChunks === null || $chunk < $maxChunks; ++$chunk) {
            $state = $this->chunkOnceMore($tenant, $id, $token);
            if ($state !== 'more') {
                return $state === 'done';
            }
        }

        return false;
    }

    /**
     * One chunk; a unique key refused by a racing writer (an e-mail stored meanwhile) runs the chunk once more, where
     * that row becomes a duplicate.
     */
    private function chunkOnceMore(TenantRef $tenant, string $id, string $token): string
    {
        try {
            return $this->support->write(fn (Effects $effects): string => $this->chunk($effects, $tenant, $id, $token));
        } catch (DuplicateRowException) {
            try {
                return $this->support->write(fn (Effects $effects): string => $this->chunk($effects, $tenant, $id, $token));
            } catch (DuplicateRowException $e) {
                throw new WriteConflictException('crm_write_conflict', $e);
            }
        }
    }

    /**
     * Settles up to `chunk_rows` pending rows under the import row's lock: `more`, `done` or `lost` (claim gone).
     */
    private function chunk(Effects $effects, TenantRef $tenant, string $id, string $token): string
    {
        $store = $this->support->store;
        if (!$store->holdsImport($id, $token)) {
            return 'lost';
        }
        $import  = $this->import($tenant, $id);
        $now     = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $actor   = $import->getCreatedById() !== null && ExactId::isValid($import->getCreatedById()) ? $import->getCreatedById() : self::SYSTEM_ACTOR;
        $context = new CrmContext($tenant, new ActorRef($actor), $now);
        $type    = $import->getSubjectType();
        $fields  = $store->fields($tenant, $type, true);
        $tags    = [];
        foreach ($store->tags($tenant) as $tag) {
            $tags[$tag->getNameKey()] = $tag->getId();
        }
        $counts = [];
        $rows   = $store->importRows($id, $this->chunkRows, true);
        foreach ($rows as $row) {
            [$outcome, $recordId, $message] = $this->row($context, $import, $row, $fields, $tags);
            if ($store->settleRow($row->getId(), $outcome, $recordId, $message)) {
                $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
            }
        }
        $store->advanceImport($id, array_sum($counts), $counts, $now);
        if (count($rows) === $this->chunkRows && $store->importRows($id, 1, true) !== []) {
            return 'more';
        }
        $store->finishImport($id, ImportStatus::Done, $now, ImportStatus::Running);
        $final = $this->import($tenant, $id)->counts();
        $effects->event(new ImportCompleted($tenant, $context->actor, $now, $type, $id, $final));
        $this->support->audit($effects, $context, 'crm.import.completed', $type, $id, $final);

        return 'done';
    }

    /**
     * Settles one row: the outcome, the record and the error key.
     *
     * @param list<FieldDefinition> $fields
     * @param array<string, string> $tags tag name key => id
     *
     * @return array{RowOutcome, string|null, string|null}
     */
    private function row(CrmContext $context, Import $import, ImportRow $row, array $fields, array $tags): array
    {
        $store                        = $this->support->store;
        $type                         = $import->getSubjectType();
        [$input, $tagNames, $company] = ImportMapping::input($type, $row->getData(), $import->getMapping());
        try {
            $values = RecordValidator::values($type, $input->values, $type === SubjectType::Company);
            $tagIds = [];
            foreach ($tagNames as $name) {
                $tagId = $tags[mb_strtolower($name, 'UTF-8')] ?? null;
                if ($tagId === null) {
                    throw InvalidInputException::field('tags', 'error.tag_unknown');
                }
                $tagIds[] = $tagId;
            }
            $existing = null;
            if ($type === SubjectType::Contact && ($values['email'] ?? null) !== null) {
                $existing = $store->contactByEmailKey($context->tenant, mb_strtolower((string) $values['email'], 'UTF-8'));
            }
            if ($existing instanceof Contact) {
                if ($import->getDuplicatePolicy() === DuplicatePolicy::Skip || !$existing->isActive()) {
                    return [RowOutcome::Duplicate, $existing->getId(), null];
                }
                $existing->apply($values);
                RecordValidator::contactName($existing->values());
                $existing->changeCustom(RecordValidator::custom(($input->custom ?? []) + $existing->getCustom(), $fields, $existing->getCustom()));
                $existing->touch($context->now, $context->actor->value);
                $this->attach($context, $existing, $tagIds, $company);
                $this->support->activity($context, $type, $existing->getId(), ActivityKind::Imported, ['import' => $import->getId()]);
                $store->flush();

                return [RowOutcome::Updated, $existing->getId(), null];
            }
            $record = $type === SubjectType::Company
                ? new Company($this->support->id(), $context->tenant->value, $context->now, $context->actor->value)
                : new Contact($this->support->id(), $context->tenant->value, $context->now, $context->actor->value);
            $record->apply($values);
            if ($record instanceof Contact) {
                RecordValidator::contactName($record->values());
            }
            $record->changeCustom(RecordValidator::custom($input->custom ?? [], $fields, []));
        } catch (InvalidInputException $e) {
            return [RowOutcome::Invalid, null, array_values($e->errors)[0] ?? 'error.invalid'];
        }
        if (!$this->counter->tryTake($context->tenant, $type)) {
            return [RowOutcome::Limit, null, null];
        }
        $store->add($record); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $store->flush();
        $this->support->activity($context, $type, $record->getId(), ActivityKind::Imported, ['import' => $import->getId()]);
        $this->attach($context, $record, $tagIds, $company);
        $store->flush();

        return [RowOutcome::Created, $record->getId(), null];
    }

    /**
     * Adds the row's tags and, for a contact, the affiliation to the named company (existing active ones only).
     *
     * @param list<string> $tagIds
     */
    private function attach(CrmContext $context, CrmRecord $record, array $tagIds, ?string $company): void
    {
        $store = $this->support->store;
        $type  = $record::type();
        if ($tagIds !== []) {
            $have = array_map(static fn (Tagging $t): string => $t->getTagId(), $store->taggings($context->tenant, $type, [$record->getId()]));
            (new Taggings($this->support))->set($context, $type, $record->getId(), array_values(array_unique([...$have, ...$tagIds])));
        }
        if ($company === null || !$record instanceof Contact) {
            return;
        }
        [$matches] = $store->list($context->tenant, new ListQuery(SubjectType::Company, $company, perPage: 5));
        foreach ($matches as $match) {
            if (mb_strtolower($match->label(), 'UTF-8') !== mb_strtolower($company, 'UTF-8')) {
                continue;
            }
            foreach ($store->affiliations($context->tenant, SubjectType::Contact, $record->getId()) as $affiliation) {
                if ($affiliation->getCompanyId() === $match->getId()) {
                    return;
                }
            }
            $store->add(new Affiliation($this->support->id(), $context->tenant->value, $record->getId(), $match->getId(), null, $store->affiliations($context->tenant, SubjectType::Contact, $record->getId()) === [], $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

            return;
        }
    }

    private function import(TenantRef $tenant, string $id): Import
    {
        $import = RecordId::isValid($id) ? $this->support->store->import($tenant, $id) : null;
        if (!$import instanceof Import) {
            throw new NotAvailableException();
        }

        return $import;
    }

    /**
     * Active custom fields of a type.
     *
     * @return list<FieldDefinition>
     */
    private function activeFields(TenantRef $tenant, SubjectType $type): array
    {
        return array_values(array_filter($this->support->store->fields($tenant, $type, false), static fn (FieldDefinition $f): bool => !$f->isArchived()));
    }

    private function staleBefore(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval('PT' . $this->staleSeconds . 'S'));
    }
}
