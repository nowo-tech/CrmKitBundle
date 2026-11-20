<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\NextActionView;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskState;

use function array_values;

/**
 * Next actions as views (0.3.0): the built-in ones are always shown; a host task is shown only when the task port
 * returns its state for this actor (one call per page), else `taskHidden` without its type or id. Stateless.
 *
 * @internal
 */
final readonly class NextActionViews
{
    /**
     * Wires the port.
     */
    public function __construct(private CrmTaskPort $tasks)
    {
    }

    /**
     * The views of the rows.
     *
     * @param list<NextAction> $rows
     *
     * @return list<NextActionView>
     */
    public function of(CrmContext $context, array $rows): array
    {
        $refs = [];
        foreach ($rows as $row) {
            $type = $row->getTaskType();
            $id   = $row->getTaskId();
            if ($type !== null && $id !== null && $type !== BuiltInCrmTaskPort::TYPE) {
                $ref               = new CrmTaskRef($type, $id);
                $refs[$ref->key()] = $ref;
            }
        }
        $states = $refs === [] ? [] : $this->tasks->states($context->tenant, $context->actor, $context->hostContext, array_values($refs));
        $views  = [];
        foreach ($rows as $row) {
            $type    = $row->getTaskType();
            $builtIn = $type === BuiltInCrmTaskPort::TYPE;
            $state   = $builtIn || $type === null || $row->getTaskId() === null ? null : ($states[$type . ':' . $row->getTaskId()] ?? null);
            $shown   = $builtIn || $state instanceof CrmTaskState;
            $views[] = new NextActionView(
                $row->getId(),
                $row->getSubjectType(),
                $row->getSubjectId(),
                $row->getTitle(),
                $row->getDueAt(),
                $row->getAssigneeId(),
                $row->getStatus(),
                $shown ? $type : null,
                $shown ? $row->getTaskId() : null,
                !$shown,
                $state?->url,
                $row->getCreatedAt(),
            );
        }

        return $views;
    }
}
