<?php

namespace App\Listeners;

use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\StoredEventRecorded;

/**
 * UC-B001: workflow_request.approved を受けてバックオフィスタスクを自動作成する。
 * これはProjectionの再生成対象ではない(正データを新規作成する副作用を持つため)。
 * .claude/skills/add-domain-event / add-projection の区別を参照。
 */
class CreateBackOfficeTaskOnApproval
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function handle(StoredEventRecorded $event): void
    {
        if ($event->storedEvent->event_type !== 'workflow_request.approved') {
            return;
        }

        $workflowRequestId = $event->storedEvent->payload['workflow_request_id'];

        $this->commandBus->dispatch(new CreateBackOfficeTaskFromApproval($workflowRequestId));
    }
}
