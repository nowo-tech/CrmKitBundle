<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunner;
use Nowo\CrmKitBundle\Application\Port\ImportRunDispatcherInterface;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\ImportCompleted;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
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
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Read\ImportRowView;
use Nowo\CrmKitBundle\Domain\Read\ImportView;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
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
use function min;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * CSV and vCard imports (contract sections 9 and 15.4): upload parses into rows; confirm claims the import with a
 * conditional UPDATE (one winner); run settles pending rows in chunks, one transaction each, holding the import row
 * lock for the chunk so two runners never process the same rows; a stale claim is taken over. **(0.10.0)** A HubSpot
 * import runs through the same claim, one HubSpot page per unit ({@see HubSpotRunner}); the resume command also takes
 * the imports whose `resume_after` passed; with `import.messenger.enabled` a confirmed import is queued and "Continue"
 * dispatches the background run ({@see self::runQueued()}). Stateless.
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
        private PartyCatalogue $catalogue = new PartyCatalogue(),
        private int $maxRows = 5000,
        private int $maxBytes = 5242880,
        private int $chunkRows = 100,
        private int $staleSeconds = 600,
        private ?HubSpotRunner $hubspot = null,
        private int $pagesPerRequest = 2,
        private ?ImportRunDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Parses and stores a file.
     */
    public function upload(SubjectType $type, ImportFormat $format, string $fileName, string $bytes): string
    {
        $context = $this->support->access->context(CrmPermission::Import);
        $name    = trim($fileName) === '' || mb_strlen($fileName) > 255 || !ExactId::isValid(trim($fileName), 1020) ? 'import' : trim($fileName);
        if (!$format->isFile()) {
            // 0.10.0: a HubSpot import starts from its connection (HubSpotImportServiceInterface::start()).
            throw new ImportFileException('import.error.read');
        }
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
        return $this->checked($id, null, $rows);
    }

    /**
     * The import with its first rows checked against another mapping (0.4.0, the import modal's preview step); nothing
     * is stored.
     */
    public function previewMapping(string $id, array $mapping, int $rows = 20): ImportView
    {
        return $this->checked($id, $mapping, $rows);
    }

    /**
     * The acting person's unfinished imports.
     */
    public function unfinished(int $limit = 5): array
    {
        $context = $this->support->access->context(CrmPermission::Import);

        return $this->support->read(fn (): array => $this->support->store->unfinishedImports($context->tenant, $context->actor->value, max(1, $limit)));
    }

    /**
     * The import with its first rows checked against the given mapping (null: the stored one).
     *
     * @param array<string, string>|null $with
     */
    private function checked(string $id, ?array $with, int $rows): ImportView
    {
        $context = $this->support->access->context(CrmPermission::Import);

        return $this->support->read(function () use ($context, $id, $with, $rows): ImportView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import  = $this->import($context->tenant, $id);
            $fields  = $this->activeFields($context->tenant, $import->getSubjectType());
            $mapping = $with ?? $import->getMapping();
            if ($with !== null && !$import->getFormat()->isFile()) {
                // A HubSpot import's mapping is HubSpotImportServiceInterface::schema()'s.
                throw InvalidInputException::field('mapping', 'import.error.mapping');
            }
            if ($with !== null && !ImportMapping::valid($with, $import->getColumns(), ImportMapping::targets($import->getSubjectType(), $fields))) {
                throw InvalidInputException::field('mapping', 'import.error.mapping');
            }
            $views = [];
            foreach ($this->support->store->importRows($id, max(1, min(100, $rows)), false) as $row) {
                if (!$import->getFormat()->isFile()) {
                    // 0.10.0: a HubSpot row holds the object kind and its HubSpot id only, settled when written.
                    $views[] = new ImportRowView($row->getRowNo(), $row->getData(), $row->getOutcome(), $row->getRecordId(), $row->getMessage(), [], []);

                    continue;
                }
                $errors                           = [];
                $duplicates                       = [];
                [$input, , , $purposes, $primary] = ImportMapping::input($import->getSubjectType(), $row->getData(), $mapping);
                try {
                    $this->extras($input->roles, $purposes, $primary);
                    $values = RecordValidator::values($import->getSubjectType(), $input->values, $import->getSubjectType() === SubjectType::Company);
                    if ($import->getSubjectType() === SubjectType::Contact) {
                        RecordValidator::contactName($values);
                        $email    = $values['email'] ?? null;
                        $existing = $email === null ? null : $this->support->store->contactByEmailKey($context->tenant, mb_strtolower($email, 'UTF-8'));
                        // Named only to someone who may see that contact.
                        if ($existing instanceof CrmRecord && $this->support->access->can(CrmPermission::View, SubjectRef::of(SubjectType::Contact, $existing->getId(), $existing->getOwnerId()))) {
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
                $mapping,
                $import->getDuplicatePolicy(),
                $import->getRowsTotal(),
                $import->getRowsDone(),
                $import->counts(),
                $views,
                $import->getVersion(),
                $import->getCreatedById(),
                $import->getCreatedAt(),
                $import->getClaimedAt(),
                $import->isAwaitingPerson(),
                $import->getResumeAfter(),
                $import->getErrorCode(),
            );
        });
    }

    /**
     * Claims the import.
     */
    public function confirm(string $id, array $mapping, DuplicatePolicy $policy, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Import);
        $won     = $this->support->write(function (Effects $effects) use ($context, $id, $mapping, $policy, $expectedVersion): bool { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import = $this->import($context->tenant, $id);
            if (!ImportMapping::valid($mapping, $import->getColumns(), ImportMapping::targets($import->getSubjectType(), $this->activeFields($context->tenant, $import->getSubjectType())))) {
                throw InvalidInputException::field('mapping', 'import.error.mapping');
            }
            $token = $this->support->id();
            $won   = $this->support->store->claimImport($context->tenant, $id, $expectedVersion, $token, json_encode($mapping, JSON_THROW_ON_ERROR), $policy->value, $context->now);
            if ($won && $this->dispatcher instanceof ImportRunDispatcherInterface) {
                // import.messenger.enabled: left unclaimed and due now; run() dispatches the background run.
                $this->support->store->queueImport($id, $token, $context->now);
            }

            return $won;
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
        $context = $this->support->access->context(CrmPermission::Import);
        $import  = $this->support->read(fn (): Import => $this->import($context->tenant, $id));
        if ($import->getStatus() !== ImportStatus::Running) {
            return $import->getStatus() === ImportStatus::Done;
        }
        if ($this->dispatcher instanceof ImportRunDispatcherInterface && $import->getClaimToken() === null && $import->getResumeAfter() instanceof DateTimeImmutable) {
            // 0.10.0: a queued (or time-paused) import runs in the background; one waiting for a person runs here.
            $this->dispatcher->dispatch($context->tenant, $id, $import->getResumeAfter() > $context->now ? $import->getResumeAfter() : null);

            return false;
        }
        if (!$import->getFormat()->isFile() && $maxChunks !== null) {
            // 0.10.0: a web request settles at most `import.hubspot.pages_per_request` HubSpot pages.
            $maxChunks = min($maxChunks, $this->pagesPerRequest);
        }

        $token = $import->getClaimToken();
        if ($token === null) {
            // Paused by an unattended run: claim it now (a racing "Continue" that won runs it; this one joins its claim).
            $mine  = $this->support->id();
            $token = $this->support->write(fn (): bool => $this->support->store->resumeImport($context->tenant, $id, $mine, $context->now)) ? $mine : $this->support->read(fn (): ?string => $this->import($context->tenant, $id)->getClaimToken());
        }

        // The rows of this run are attributed to the person who clicked (Continue), not to the uploader.
        return $this->runClaimed($context->tenant, $id, (string) $token, $maxChunks, $context->actor->value, true, $import->getFormat()) === 'done';
    }

    /**
     * 0.10.0, the background run (`RunCrmImport`, no signed-in person): claims the import when it is unclaimed (queued
     * or paused) or its claim is stale, runs up to `$maxChunks` units unattended and answers what follows: `more`
     * (queued again, due now), `wait` (paused until the time), `done`, or `stop` (nothing to do: finished, claimed by
     * another runner, or waiting for a person or a new token). A redelivered message finds the claim taken and stops.
     *
     * @return array{string, DateTimeImmutable|null}
     */
    public function runQueued(TenantRef $tenant, string $id, int $maxChunks): array
    {
        $now    = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $import = $this->support->read(fn (): ?Import => RecordId::isValid($id) ? $this->support->store->import($tenant, $id) : null);
        if (!$import instanceof Import || $import->getStatus() !== ImportStatus::Running || ($import->getResumeAfter() instanceof DateTimeImmutable && $import->getResumeAfter() > $now)) {
            return [$import?->getStatus() === ImportStatus::Running && $import->getResumeAfter() instanceof DateTimeImmutable ? 'wait' : 'stop', $import?->getResumeAfter()];
        }
        $token = $this->support->id();
        $won   = $this->support->write(fn (): bool => $import->getClaimToken() === null
            ? ($import->getResumeAfter() instanceof DateTimeImmutable && $this->support->store->resumeImport($tenant, $id, $token, $now))
            : $this->support->store->takeOverImport($tenant, $id, $token, $now, $this->staleBefore($now)));
        if (!$won) {
            return ['stop', null];
        }
        $state = $this->runClaimed($tenant, $id, $token, $maxChunks, null, false, $import->getFormat());
        if ($state === 'more') {
            $this->support->write(fn (): bool => $this->support->store->queueImport($id, $token, $this->clock->now()->setTimezone(new DateTimeZone('UTC'))));

            return ['more', null];
        }
        $after = $this->support->read(fn (): ?Import => $this->support->store->import($tenant, $id));

        return $state === 'done' ? ['done', null] : ($after?->getResumeAfter() instanceof DateTimeImmutable && $after->getStatus() === ImportStatus::Running ? ['wait', $after->getResumeAfter()] : ['stop', null]);
    }

    /**
     * Cancels an import.
     */
    public function cancel(string $id): void
    {
        $context = $this->support->access->context(CrmPermission::Import);
        $this->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $import = $this->import($context->tenant, $id);
            // One conditional UPDATE decides (audit VD-4): a preview, a paused import or a stale claim is cancelled; a
            // claim taken meanwhile (a "Continue" or the resume command) answers crm_import_running.
            if (!$this->support->store->cancelImport($context->tenant, $id, $context->now, $this->staleBefore($context->now))) {
                throw new ConflictException(ConflictException::IMPORT_RUNNING, $id);
            }
            $this->support->audit($effects, $context, 'crm.import.cancelled', $import->getSubjectType(), $id);
        });
    }

    /**
     * Takes over the imports whose claim is stale and runs them (resume command, no signed-in person); how many
     * finished. Nobody is signed in, so a row that needs a decision on one record (an update of an existing contact,
     * a named company) is never settled here: the run stops before it, leaves it pending and releases the claim, so the
     * next person's "Continue" takes the import at once and processes it with their own access (audit UP2-1); later
     * resume runs leave such an import alone.
     */
    public function resumeStale(int $limit = 20): int
    {
        $now      = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $finished = 0;
        foreach ($this->support->read(fn (): array => $this->support->store->staleImports($this->staleBefore($now), $limit)) as $stale) {
            $tenant = new TenantRef($stale['tenant']);
            $token  = $this->support->id();
            $won    = $this->support->write(fn (): bool => $this->support->store->takeOverImport($tenant, $stale['id'], $token, $now, $this->staleBefore($now)));
            if ($won && $this->runClaimed($tenant, $stale['id'], $token, null, null, false, $this->formatOf($tenant, $stale['id'])) === 'done') {
                ++$finished;
            }
        }
        // 0.10.0: paused HubSpot imports whose wait is over, and queued runs.
        foreach ($this->support->read(fn (): array => $this->support->store->dueImports($now, $limit)) as $due) {
            $tenant = new TenantRef($due['tenant']);
            $token  = $this->support->id();
            $won    = $this->support->write(fn (): bool => $this->support->store->resumeImport($tenant, $due['id'], $token, $now));
            if ($won && $this->runClaimed($tenant, $due['id'], $token, null, null, false, $this->formatOf($tenant, $due['id'])) === 'done') {
                ++$finished;
            }
        }

        return $finished;
    }

    /**
     * Runs chunks (HubSpot: pages) while this claim holds the import: the last state (`more` when `$maxChunks` ran
     * out).
     */
    private function runClaimed(TenantRef $tenant, string $id, string $token, ?int $maxChunks, ?string $actor, bool $attended, ImportFormat $format): string
    {
        $state = 'more';
        for ($chunk = 0; $state === 'more' && ($maxChunks === null || $chunk < $maxChunks); ++$chunk) {
            $state = $format->isFile() || !$this->hubspot instanceof HubSpotRunner
                ? $this->chunkOnceMore($tenant, $id, $token, $actor, $attended)
                : $this->hubspot->step($tenant, $id, $token, $actor ?? $this->support->read(fn (): string => self::runActor($this->import($tenant, $id))), $attended);
        }

        return $state;
    }

    /**
     * The format of an import of the tenant.
     */
    private function formatOf(TenantRef $tenant, string $id): ImportFormat
    {
        return $this->support->read(fn (): ImportFormat => $this->import($tenant, $id)->getFormat());
    }

    /**
     * The actor of an unattended run: the uploader, unless anonymised or unknown, else {@see self::SYSTEM_ACTOR}.
     */
    public static function runActor(Import $import): string
    {
        $creator = $import->getCreatedById();

        return $creator !== null && ExactId::isValid($creator) && !ActorRef::isReserved($creator) ? $creator : self::SYSTEM_ACTOR;
    }

    /**
     * One chunk; a unique key refused by a racing writer (an e-mail stored meanwhile) runs the chunk once more, where
     * that row becomes a duplicate.
     */
    private function chunkOnceMore(TenantRef $tenant, string $id, string $token, ?string $actor, bool $attended): string
    {
        try {
            return $this->support->write(fn (Effects $effects): string => $this->chunk($effects, $tenant, $id, $token, $actor, $attended));
        } catch (DuplicateRowException) {
            try {
                return $this->support->write(fn (Effects $effects): string => $this->chunk($effects, $tenant, $id, $token, $actor, $attended));
            } catch (DuplicateRowException $e) {
                throw new ConflictException(ConflictException::WRITE_CONFLICT, null, $e);
            }
        }
    }

    /**
     * Settles up to `chunk_rows` pending rows under the import row's lock: `more`, `done`, `lost` (claim gone) or
     * `paused` (unattended run stopped before a row that needs a record decision). The rows are attributed to `$actor`
     * (the person running it), else to the uploader, else to the system actor.
     */
    private function chunk(Effects $effects, TenantRef $tenant, string $id, string $token, ?string $actor, bool $attended = true): string
    {
        $store = $this->support->store;
        if (!$store->holdsImport($id, $token)) {
            return 'lost';
        }
        $import = $this->import($tenant, $id);
        $now    = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        // An anonymised uploader is no actor: the run is then the system's (audit VD2-3).
        $actor ??= self::runActor($import);
        $context = new CrmContext($tenant, new ActorRef($actor), $now);
        $type    = $import->getSubjectType();
        $fields  = $store->fields($tenant, $type, true);
        $tags    = [];
        foreach ($store->tags($tenant) as $tag) {
            $tags[$tag->getNameKey()] = $tag->getId();
        }
        $counts = [];
        $rows   = $store->importRows($id, $this->chunkRows, true);
        $this->lockParents($context, $import, $rows);
        $paused = false;
        foreach ($rows as $row) {
            [$outcome, $recordId, $message] = $this->row($effects, $context, $import, $row, $fields, $tags, $attended);
            if (!$outcome instanceof RowOutcome) {
                // Nobody signed in may decide on this row: it stays pending, and so do the rows after it.
                $paused = true;

                break;
            }
            if ($store->settleRow($row->getId(), $outcome, $recordId, $message)) {
                $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
            }
        }
        $store->advanceImport($id, array_sum($counts), $counts, $now);
        if ($paused) {
            // Waiting for a person: the claim goes, in this transaction, so "Continue" needs no stale claim.
            $store->pauseImport($id, $token, $now);

            return 'paused';
        }
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
     * Settles one row: the outcome, the record and the error key; a null outcome when the run is unattended and the row
     * needs a decision on one record (it stays pending).
     *
     * @param list<FieldDefinition> $fields
     * @param array<string, string> $tags tag name key => id
     *
     * @return array{RowOutcome|null, string|null, string|null}
     */
    private function row(Effects $effects, CrmContext $context, Import $import, ImportRow $row, array $fields, array $tags, bool $attended = true): array
    {
        $store                                             = $this->support->store;
        $type                                              = $import->getSubjectType();
        [$input, $tagNames, $company, $purposes, $primary] = ImportMapping::input($type, $row->getData(), $import->getMapping());
        try {
            [$roles, $purposes, $primaryContact] = $this->extras($input->roles, $purposes, $primary);
            $values                              = RecordValidator::values($type, $input->values, $type === SubjectType::Company);
            $tagIds                              = [];
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
                // The parent of the row's children (tags, affiliation): locked before they are written, so a merge or
                // an erasure running meanwhile is seen (its status re-read under the lock).
                $existing = $existing instanceof Contact ? ($store->lockRecords(SubjectType::Contact, $context->tenant, [$existing->getId()])[$existing->getId()] ?? null) : null;
            }
            if ($existing instanceof Contact) {
                if ($import->getDuplicatePolicy() === DuplicatePolicy::Skip || !$existing->isActive()) {
                    // The id only for a contact the person may see.
                    return [RowOutcome::Duplicate, $this->may($context, CrmPermission::View, SubjectType::Contact, $existing->getId(), $existing->getOwnerId()) ? $existing->getId() : null, null];
                }
                // Updating an existing contact is an edit of that contact (audit UP-M-2), checked before any write.
                if (!$attended) {
                    return [null, null, null];
                }
                $named = $company === null ? null : $this->companyNamed($context, $company);
                if (!$this->may($context, CrmPermission::Edit, SubjectType::Contact, $existing->getId(), $existing->getOwnerId()) || !$this->mayAttach($context, $existing, $named, $primaryContact)) {
                    return [RowOutcome::Invalid, null, 'error.forbidden'];
                }
                $existing->apply($values);
                RecordValidator::contactName($existing->values());
                $existing->changeCustom(RecordValidator::custom(($input->custom ?? []) + $existing->getCustom(), $fields, $existing->getCustom()));
                $existing->touch($context->now, $context->actor->value);
                $this->attach($effects, $context, $existing, $tagIds, $roles, $named, $purposes, $primaryContact);
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
        if ($company !== null && !$attended) {
            return [null, null, null];
        }
        $named = $company === null ? null : $this->companyNamed($context, $company);
        if (!$this->mayAttach($context, null, $named, $primaryContact)) {
            return [RowOutcome::Invalid, null, 'error.forbidden'];
        }
        if (!$this->counter->tryTake($context->tenant, $type)) {
            return [RowOutcome::Limit, null, null];
        }
        $store->add($record); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $store->flush();
        $this->support->activity($context, $type, $record->getId(), ActivityKind::Imported, ['import' => $import->getId()]);
        $this->attach($effects, $context, $record, $tagIds, $roles, $named, $purposes, $primaryContact);
        $store->flush();

        return [RowOutcome::Created, $record->getId(), null];
    }

    /**
     * Adds the row's tags and roles (an import only adds them) and, for a contact, the affiliation to the named company
     * (`$named`: the id {@see self::mayAttach()} checked; existing active ones only) with the row's purposes (added to
     * the stored ones) and primary-contact flag.
     *
     * @param list<string> $tagIds
     * @param list<string> $roles
     * @param list<string> $purposes
     */
    private function attach(Effects $effects, CrmContext $context, CrmRecord $record, array $tagIds, array $roles, ?string $named, array $purposes, ?bool $primaryContact): void
    {
        $store = $this->support->store;
        $type  = $record::type();
        if ($tagIds !== []) {
            $have = array_map(static fn (Tagging $t): string => $t->getTagId(), $store->taggings($context->tenant, $type, [$record->getId()]));
            (new Taggings($this->support))->set($context, $type, $record->getId(), array_values(array_unique([...$have, ...$tagIds])));
        }
        if ($roles !== []) {
            $have = array_map(static fn (RoleAssignment $r): string => $r->getRoleKey(), $store->roleAssignments($context->tenant, $type, [$record->getId()]));
            (new Roles($this->support, $this->catalogue))->set($context, $type, $record->getId(), array_values(array_unique([...$this->catalogue->knownRoles($have), ...$roles])), $effects);
        }
        // Contact first (locked by lockParents(), or new), then the company: the order of affiliate(); its status
        // re-read under the lock, so a company merged or trashed meanwhile gets no affiliation.
        $locked = $named === null || !$record instanceof Contact ? [] : $store->lockRecords(SubjectType::Company, $context->tenant, [$named]);
        $match  = array_values(array_filter($locked, static fn (CrmRecord $m): bool => $m->isActive()))[0] ?? null;
        if (!$match instanceof CrmRecord || !$record instanceof Contact) {
            return;
        }
        $affiliation = null;
        $others      = $store->affiliations($context->tenant, SubjectType::Contact, $record->getId());
        foreach ($others as $row) {
            if ($row->getCompanyId() === $match->getId()) {
                $affiliation = $row;
            }
        }
        if (!$affiliation instanceof Affiliation) {
            $affiliation = new Affiliation($this->support->id(), $context->tenant->value, $record->getId(), $match->getId(), null, $others === [], $context->now);
            $store->add($affiliation); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($purposes !== []) {
            $affiliation->setPurposes([...$affiliation->getPurposes(), ...$purposes]);
        }
        if ($primaryContact !== null && $primaryContact !== $affiliation->isPrimaryContact()) {
            // The company is locked: at most one primary contact per company.
            if ($primaryContact) {
                RecordService::markPrimaryContact($store->affiliations($context->tenant, SubjectType::Company, $match->getId()), $record->getId());
            }
            $affiliation->setPrimaryContact($primaryContact);
            $this->support->activity($context, SubjectType::Company, $match->getId(), ActivityKind::PrimaryContact, ['contact' => $primaryContact ? $record->getId() : null]);
        }
    }

    /**
     * Whether the row may link the named company: `view` on it, and, when the row's primary-contact flag would change
     * for it (a new contact has none), `edit` on it, as in the UI. Checked before the row writes anything.
     */
    private function mayAttach(CrmContext $context, ?Contact $contact, ?string $named, ?bool $primaryContact): bool
    {
        if ($named === null) {
            return true;
        }
        $owner = $this->support->store->record(SubjectType::Company, $context->tenant, $named)?->getOwnerId();
        // The named company must be one the person may see (else nothing is linked and nothing is told about it).
        if (!$this->may($context, CrmPermission::View, SubjectType::Company, $named, $owner)) {
            return false;
        }
        if ($primaryContact === null) {
            return true;
        }
        $current = false;
        foreach ($contact instanceof Contact ? $this->support->store->affiliations($context->tenant, SubjectType::Contact, $contact->getId()) : [] as $affiliation) {
            $current = $current || ($affiliation->getCompanyId() === $named && $affiliation->isPrimaryContact());
        }

        return $current === $primaryContact || $this->may($context, CrmPermission::Edit, SubjectType::Company, $named, $owner);
    }

    /**
     * Whether the person running the import holds the permission on one record.
     */
    private function may(CrmContext $context, CrmPermission $permission, SubjectType $type, string $id, ?string $owner): bool
    {
        try {
            $this->support->access->require($context, $permission, SubjectRef::of($type, $id, $owner));
        } catch (AccessDeniedException) {
            return false;
        }

        return true;
    }

    /**
     * The roles, purposes and primary-contact flag of a row, checked against the catalogues.
     *
     * @param list<string>|null $roles
     * @param list<string> $purposes
     *
     * @throws InvalidInputException
     *
     * @return array{list<string>, list<string>, bool|null}
     */
    private function extras(?array $roles, array $purposes, ?string $primary): array
    {
        $flag = $primary === null ? null : ImportMapping::yes($primary);
        if ($primary !== null && $flag === null) {
            throw InvalidInputException::field('primary_contact', 'error.invalid');
        }

        return [$this->catalogue->checkRoles($roles ?? []), $this->catalogue->checkPurposes($purposes), $flag];
    }

    /**
     * The company a row names: the first active company of the tenant the person may see (resource scoping) whose name
     * matches exactly, case-insensitively; an invisible company is unknown (audit UP2-3).
     */
    private function companyNamed(CrmContext $context, string $name): ?string
    {
        [$matches] = $this->support->store->list($context->tenant, new ListQuery(SubjectType::Company, $name, perPage: 5), $this->support->access->visibleOwners($context));
        foreach ($matches as $match) {
            if (mb_strtolower($match->label(), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                return $match->getId();
            }
        }

        return null;
    }

    /**
     * Locks, before any row is settled, the records the chunk's rows may change (UD2-L-1): the existing contacts of
     * their e-mails in id order, then the companies they name in id order. Every lock of the chunk is so taken in the
     * order of every other path (contacts before companies, each kind by id), never row by row.
     *
     * @param list<ImportRow> $rows
     */
    private function lockParents(CrmContext $context, Import $import, array $rows): void
    {
        if ($import->getSubjectType() !== SubjectType::Contact) {
            return;
        }
        $store     = $this->support->store;
        $emails    = [];
        $companies = [];
        foreach ($rows as $row) {
            [$input, , $company] = ImportMapping::input(SubjectType::Contact, $row->getData(), $import->getMapping());
            $email               = mb_strtolower(trim($input->values['email'] ?? ''), 'UTF-8');
            if ($email !== '') {
                $emails[$email] = $email;
            }
            $named = $company === null ? null : $this->companyNamed($context, $company);
            if ($named !== null) {
                $companies[$named] = $named;
            }
        }
        $store->lockRecords(SubjectType::Contact, $context->tenant, $store->contactIdsByEmailKeys($context->tenant, array_values($emails)));
        $store->lockRecords(SubjectType::Company, $context->tenant, array_values($companies));
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
