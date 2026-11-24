<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;

use function bin2hex;
use function random_bytes;

/**
 * Choices of the funnel pages (0.3.0): the visible companies and contacts (at most 100 each, by name), the owners, the
 * stages of a funnel, the lost reasons, and the dedupe key of a next-action form. Stateless.
 *
 * @internal
 */
final readonly class PipelineForms
{
    /**
     * @param list<string> $lostReasons
     */
    public function __construct(private RecordForms $records, private CrmReaderInterface $reader, private array $lostReasons = [])
    {
    }

    /**
     * Label ⇒ id of the active records of a type the actor may see (at most 100).
     *
     * @return array<string, string>
     */
    public function records(SubjectType $type): array
    {
        return $this->records->records($type);
    }

    /**
     * Name ⇒ id of the owners.
     *
     * @return array<string, string>
     */
    public function owners(): array
    {
        return $this->records->owners();
    }

    /**
     * Name ⇒ id of the stages of a funnel that are not archived (only open ones with `$openOnly`).
     *
     * @return array<string, string>
     */
    public function stages(FunnelView $funnel, bool $openOnly = false): array
    {
        $choices = [];
        foreach ($funnel->stages as $stage) {
            if (!$stage->archived && (!$openOnly || $stage->kind === StageKind::Open)) {
                $choices[$stage->name . ' · ' . $stage->position] = $stage->id;
            }
        }

        return $choices;
    }

    /**
     * Label key ⇒ key of the lost reasons.
     *
     * @return array<string, string>
     */
    public function reasons(): array
    {
        $choices = [];
        foreach ($this->lostReasons as $reason) {
            $choices['lost_reason.' . $reason] = $reason;
        }

        return $choices;
    }

    /**
     * The party role choices of the statistics filter.
     *
     * @return array<string, string>
     */
    public function roles(): array
    {
        return $this->records->roles();
    }

    /**
     * Name ⇒ id of the tenant's tags.
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        $tags = [];
        foreach ($this->reader->tags() as $tag) {
            $tags[$tag->name] = $tag->id;
        }

        return $tags;
    }

    /**
     * A new dedupe key for a next-action form (64 hex): a double submit of the same form stores one action.
     */
    public static function dedupe(): string
    {
        return bin2hex(random_bytes(32));
    }
}
