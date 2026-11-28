<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequests;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * 申請の一括取消。各件は`CancelWorkflowRequest`(1件の取消と同じ検査・記録)をdispatchして行い、
 * 1件でも失敗したら全体を戻す。開始時点で取消可能だった申請が連鎖で既に取消済みなら成功として飛ばす。
 *
 * @implements CommandHandler<CancelWorkflowRequests>
 */
class CancelWorkflowRequestsHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof CancelWorkflowRequests);

        $initial = WorkflowRequest::query()
            ->whereIn('id', $command->workflowRequestIds)
            ->get(['id', 'title', 'status'])
            ->keyBy('id');

        return BulkItemRunner::run($command->workflowRequestIds, function (string $id) use ($command, $initial): bool {
            $startedCancellable = in_array($initial->get($id)?->status, WorkflowRequestStatus::cancellable(), true);

            if ($startedCancellable) {
                $current = WorkflowRequest::query()->whereKey($id)->value('status');
                if ($current === WorkflowRequestStatus::CANCELLED) {
                    return false;
                }
            }

            $this->commandBus->dispatch(new CancelWorkflowRequest(
                workflowRequestId: $id,
                cancelledByUserId: $command->cancelledByUserId,
                reason: $command->reason,
            ));

            return true;
        }, fn (string $id): ?string => $initial->get($id)?->title);
    }
}
