<?php

namespace App\Domain\Workflow\Reactors;

use App\Domain\BackOffice\Commands\CreateBackOfficeTaskFromApproval;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Events\WorkflowRequestApproved;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * UC-B001: workflow_request.approved を受けてバックオフィスタスクを自動作成する。
 * これはProjector(BackOfficeTaskProjector)とは別物のサガ/プロセスマネージャであり、
 * 「イベントを見て新しいCommandを発行する」副作用そのもの。`event-sourcing:replay`は
 * Projectorのみを対象に取れる(引数でProjector名を指定する)ため、Reactorであるこのクラスを
 * 指定しない限り再実行されず、backoffice_taskの重複作成は起きない。
 */
class CreateBackOfficeTaskOnApprovalReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onWorkflowRequestApproved(WorkflowRequestApproved $event): void
    {
        $this->commandBus->dispatch(new CreateBackOfficeTaskFromApproval($event->aggregateRootUuid()));
    }
}
