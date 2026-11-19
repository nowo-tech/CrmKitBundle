<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\ConsentRecorded;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ConsentPurpose;

use function in_array;
use function trim;

/**
 * Consent per contact and purpose (contract section 16.1): insert only; the latest row per purpose is the current
 * state (UUIDv7 ids order rows of the same second). Stateless.
 */
final readonly class ConsentService implements ConsentServiceInterface
{
    /**
     * @param list<string> $purposes the configured purposes
     */
    public function __construct(private WriteSupport $support, private array $purposes)
    {
    }

    /**
     * Records a decision.
     */
    public function record(string $contactId, string $purpose, bool $granted, ConsentSource $source, ?string $evidence = null): string
    {
        $context = $this->support->access->base();
        try {
            $purpose = new ConsentPurpose($purpose);
        } catch (InvalidArgumentException) {
            throw InvalidInputException::field('purpose', 'error.invalid');
        }
        if (!in_array($purpose->value, $this->purposes, true)) {
            throw InvalidInputException::field('purpose', 'error.consent_purpose');
        }
        $evidence = $evidence === null || trim($evidence) === '' ? null : trim($evidence);
        if ($evidence !== null && RecordValidator::check($evidence, 255, 'text') !== null) {
            throw InvalidInputException::field('evidence', 'error.invalid');
        }

        return $this->support->write(function (Effects $effects) use ($context, $contactId, $purpose, $granted, $source, $evidence): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->load($context, SubjectType::Contact, $contactId, CrmPermission::Edit, lock: true);
            $id = $this->support->id();
            $this->support->store->add(new Consent($id, $context->tenant->value, $contactId, $purpose->value, $granted, $source, $evidence, $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, SubjectType::Contact, $contactId, $granted ? ActivityKind::ConsentGiven : ActivityKind::ConsentWithdrawn, ['purpose' => $purpose->value]);
            $effects->event(new ConsentRecorded($context->tenant, $context->actor, $context->now, $contactId, $purpose->value, $granted));
            $this->support->audit($effects, $context, 'crm.consent.recorded', SubjectType::Contact, $contactId, ['purpose' => $purpose->value, 'granted' => $granted]);

            return $id;
        });
    }

    /**
     * The current state per purpose.
     */
    public function current(string $contactId): array
    {
        $context = $this->support->access->base();

        return $this->support->read(function () use ($context, $contactId): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->load($context, SubjectType::Contact, $contactId, CrmPermission::View, anyStatus: true);
            $state = [];
            foreach ($this->support->store->consents($context->tenant, $contactId) as $consent) {
                $state[$consent->getPurpose()] ??= $consent->isGranted();
            }

            return $state;
        });
    }
}
