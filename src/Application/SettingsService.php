<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;

/**
 * Per-tenant settings (contract section 6.2): version 0 = never saved (insert; a racing insert is stale), else the row
 * version. Stateless.
 */
final readonly class SettingsService implements CrmSettingsInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support)
    {
    }

    /**
     * The settings.
     */
    public function get(): array
    {
        $context  = $this->support->access->context(CrmPermission::Manage);
        $settings = $this->support->read(fn (): ?CrmSettings => $this->support->store->settings($context->tenant));
        $version  = $settings?->getVersion() ?? 0;
        $settings ??= new CrmSettings($context->tenant->value, $context->now);

        return [
            'trash_days'       => $settings->getTrashDays(),
            'timeline_years'   => $settings->getTimelineYears(),
            'import_keep_days' => $settings->getImportKeepDays(),
            'merge_keep_days'  => $settings->getMergeKeepDays(),
            'version'          => $version,
        ];
    }

    /**
     * Saves the settings.
     */
    public function update(int $trashDays, int $timelineYears, int $importKeepDays, int $mergeKeepDays, int $expectedVersion): void
    {
        $context = $this->support->access->context(CrmPermission::Manage);
        try {
            $this->support->write(function (Effects $effects) use ($context, $trashDays, $timelineYears, $importKeepDays, $mergeKeepDays, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $settings = $this->support->store->settings($context->tenant);
                if (($settings?->getVersion() ?? 0) !== $expectedVersion) {
                    throw new ConflictException(ConflictException::SETTINGS_STALE);
                }
                if (!$settings instanceof CrmSettings) {
                    $settings = new CrmSettings($context->tenant->value, $context->now);
                    $this->support->store->add($settings); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
                try {
                    $settings->change($trashDays, $timelineYears, $importKeepDays, $mergeKeepDays, $context->actor->value, $context->now);
                } catch (InvalidArgumentException $e) {
                    throw InvalidInputException::field($e->getMessage(), 'error.out_of_range');
                }
                $this->support->audit($effects, $context, 'crm.settings.changed');
            }, ConflictException::SETTINGS_STALE);
        } catch (DuplicateRowException $e) {
            throw new ConflictException(ConflictException::SETTINGS_STALE, null, $e);
        }
    }
}
