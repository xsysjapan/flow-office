<?php

namespace App\Listeners;

use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\StoredEventRecorded;

/**
 * UC-B001: workflow_request.approved を受けてバックオフィスタスクを自動作成する。
 * これはProjector(BackOfficeTaskProjector)とは別物のサガ/プロセスマネージャであり、
 * 「イベントを見て新しいCommandを発行する」副作用そのもの。projections:rebuildは
 * StoredEvent::project()を直接呼ぶだけでLaravelのイベントディスパッチャを経由しない
 * ため、再生成時にこのリスナーが再度発火してbackoffice_taskを重複作成することはない
 * (backoffice_task.created イベント自体はBackOfficeTaskProjectorが再生成時に読み直す)。
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
