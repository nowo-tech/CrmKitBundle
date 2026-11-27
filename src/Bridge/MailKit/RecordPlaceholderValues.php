<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;

/**
 * The placeholder values of a company or contact (0.13.0): its record view's core fields, its name (`name` /
 * `full_name`) and its owner's name, only for an active record the person may view. Stateless.
 *
 * @internal
 */
final class RecordPlaceholderValues
{
    /**
     * The values (empty without a record, or for a record that is not active).
     *
     * @return array<string, string|null>
     */
    public static function of(CrmReaderInterface $reader, SubjectType $type, ?string $id): array
    {
        if ($id === null) {
            return [];
        }
        $view = $reader->record($type, $id);
        if ($view->status !== RecordStatus::Active) {
            return [];
        }

        return $view->values + [
            $type === SubjectType::Contact ? 'full_name' : 'name' => $view->label,
            'owner_name'                                          => $view->ownerName,
        ];
    }
}
