<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\ApproveWorkflowRequests;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * 承認の一括。各件は`ApproveWorkflowRequest`(1件の承認と同じ検査・記録)をdispatchして行い、
 * 1件でも失敗したら全体を戻す。開始時点で提出済みだった申請が、まとめ申請の兄弟の承認などの連鎖で
 * 既に承認済みになっていたら成功として飛ばす。
 *
 * @implements CommandHandler<ApproveWorkflowRequests>
 */
class ApproveWorkflowRequestsHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ApproveWorkflowRequests);

        $initial = WorkflowRequest::query()
            ->whereIn('id', $command->workflowRequestIds)
            ->get(['id', 'title', 'status'])
            ->keyBy('id');

        return BulkItemRunner::run($command->workflowRequestIds, function (string $id) use ($command, $initial): bool {
            if ($initial->get($id)?->status === WorkflowRequestStatus::SUBMITTED) {
                $current = WorkflowRequest::query()->whereKey($id)->value('status');
                if ($current === WorkflowRequestStatus::APPROVED) {
                    return false;
                }
            }

            $this->commandBus->dispatch(new ApproveWorkflowRequest(
                workflowRequestId: $id,
                approvedByUserId: $command->approvedByUserId,
            ));

            return true;
        }, fn (string $id): ?string => $initial->get($id)?->title);
    }
}
