<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeaveRequest\Commands\MigratePaidLeaveRequest;
use App\Models\LeaveRequestWorkflowLink;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 本変更前に申請された有給申請(旧`paid_leave.*`・cutover後の`paid_leave_account.*`由来)を
 * 有給申請の集約へ引き継ぐ運用コマンド(仕様確定事項I。変更セット20261009-keep-leave-work-type-on-edit)。
 *
 * - 対象は`paid_leave_requests`(現在のReadModel)のうち、入力系統が新系統(`paid_request`)でない申請。
 *   集約に既に状態がある申請は対象外(再実行しても二重に記録しない)。
 * - 現在の状態を`paid_leave_request.migrated`として今の時点に追記する(Commandは`MigratePaidLeaveRequest`)。
 *   消化記録IDは有給口座の集約(`usageIdForRequest`)から取る。
 * - 却下済みのワークフローに紐づく申請中の申請は、取消(cancelled)として引き継ぎ、同じ処理内で口座の
 *   未確定の消化記録を`CancelPaidLeaveUsage`(viaReactor。消化記録が無い・取消済みなら何もしない)で取り消す。
 *   取消を`paid_leave_request.cancelled`経由(通常の取消)にしないのは、却下済みワークフローに反応する
 *   勤怠の締め判定・ワークフローの取消Reactorを通さないため。
 * - 差し戻された申請で消化記録が取り消されていない場合は、一覧に警告を出す。
 * - cutover前(消化記録なし)の承認済み申請は引き継ぎ時に hasUsage=false とし、集約が取消を拒否する。
 * - 既定は試し実行(対象一覧と件数の表示のみ)。`--apply`を付けたときだけ記録する。
 * - 1件の失敗で止めず、失敗は最後にまとめて表示する。
 */
class MigratePaidLeaveRequestsCommand extends Command
{
    protected $signature = 'paid-leave:migrate-requests {--apply : 実際に有給申請の集約へ引き継ぐ(指定しない場合は試し実行)}';

    protected $description = '本変更前に申請された有給申請を有給申請の集約へ引き継ぐ(既定は試し実行、--applyで実行、冪等)';

    public function handle(CommandBus $commandBus): int
    {
        $apply = (bool) $this->option('apply');

        $requests = PaidLeaveRequest::query()
            ->where(function ($query) {
                $query->whereNull('input_source')
                    ->orWhere('input_source', '!=', PaidLeaveRequest::SOURCE_PAID_REQUEST);
            })
            ->orderBy('target_date')
            ->orderBy('id')
            ->get();

        $rows = [];
        $failures = [];
        $migrated = 0;
        $skipped = 0;
        $cancelledByRejection = 0;
        $warnings = 0;

        foreach ($requests as $request) {
            $requestId = (string) $request->id;

            try {
                // 集約に既に状態があるもの(二重引き継ぎ防止)は対象外。
                if (PaidLeaveRequestAggregate::retrieve($requestId)->status() !== 'none') {
                    $skipped++;
                    $rows[] = [$requestId, $request->status, 'skip(引き継ぎ済み)', '-'];

                    continue;
                }

                $plan = $this->planOf($request);

                if ($plan['warning'] !== null) {
                    $warnings++;
                }
                if ($plan['rejected']) {
                    $cancelledByRejection++;
                }

                if ($apply) {
                    DB::transaction(function () use ($commandBus, $plan) {
                        $commandBus->dispatch($plan['command']);

                        if ($plan['cancelUsage']) {
                            $commandBus->dispatch(new CancelPaidLeaveUsage(
                                userId: $plan['command']->userId,
                                usageId: null,
                                cancelledByUserId: null,
                                reason: '却下済みワークフローに紐づく有給申請の引き継ぎに伴う消化記録の取消',
                                paidLeaveRequestId: $plan['command']->paidLeaveRequestId,
                                viaReactor: true,
                            ));
                        }
                    });
                }

                $migrated++;
                $rows[] = [
                    $requestId,
                    $request->status,
                    ($apply ? 'migrated' : 'dry-run').' → '.$plan['command']->status
                        .($plan['rejected'] ? '(却下済みのため取消。消化記録も取消)' : ''),
                    $plan['warning'] ?? '-',
                ];
            } catch (Throwable $e) {
                $failures[] = ['paid_leave_request_id' => $requestId, 'error' => $e->getMessage()];
                $rows[] = [$requestId, $request->status, 'NG', $e->getMessage()];
            }
        }

        $this->table(['paid_leave_request_id', '現在の状態', '結果', '警告'], $rows);
        $this->info(($apply ? '' : '(dry-run) ')."対象 {$requests->count()} 件 / 引き継ぎ {$migrated} 件 / 引き継ぎ済みのため対象外 {$skipped} 件 / 却下済みワークフローのため取消として引き継ぐ {$cancelledByRejection} 件 / 警告 {$warnings} 件 / 失敗 ".count($failures).' 件');

        if (count($failures) > 0) {
            $this->table(['paid_leave_request_id', 'error'], $failures);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 1申請分の引き継ぎ内容を決める。
     *
     * @return array{command: MigratePaidLeaveRequest, rejected: bool, cancelUsage: bool, warning: ?string}
     */
    private function planOf(PaidLeaveRequest $request): array
    {
        $requestId = (string) $request->id;
        $userId = (string) $request->user_id;
        $account = PaidLeaveAccountAggregate::retrieve($userId);
        $usageId = $account->usageIdForRequest($requestId);
        $workflowRequestId = $this->workflowRequestIdOf($requestId);

        $status = (string) $request->status;
        $rejected = false;
        $cancelUsage = false;
        $warning = null;

        // ワークフローの対応(休暇申請文脈の対応表)が無いと、以後の承認・差戻しの連動が効かない。
        if ($workflowRequestId === null && in_array($status, [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::RETURNED], true)) {
            $warning = 'ワークフローの対応が無い(承認・差戻しの連動が効かない。要確認)';
        }

        switch ($status) {
            case PaidLeaveRequestStatus::SUBMITTED:
                if ($workflowRequestId !== null
                    && WorkflowRequest::query()->whereKey($workflowRequestId)->value('status') === WorkflowRequestStatus::REJECTED
                ) {
                    // 却下済みのワークフローに紐づく申請中の申請は取消として引き継ぎ、未確定の消化記録も取り消す。
                    $status = PaidLeaveRequestStatus::CANCELLED;
                    $rejected = true;

                    if ($usageId !== null && $account->usageStatus($usageId) !== 'cancelled') {
                        $cancelUsage = true;
                    }
                }
                break;

            case PaidLeaveRequestStatus::RETURNED:
                if ($usageId !== null && $account->usageStatus($usageId) !== 'cancelled') {
                    $warning = '差戻しなのに消化記録が取り消されていない(要確認)';
                }
                break;

            case PaidLeaveRequestStatus::APPROVED:
                if ($usageId === null && $request->input_source === PaidLeaveRequest::SOURCE_PAID_ACCOUNT) {
                    $warning = '承認済みだが消化記録が無い(cutover後の申請として想定外。要確認)';
                }
                break;
        }

        $command = new MigratePaidLeaveRequest(
            paidLeaveRequestId: $requestId,
            userId: $userId,
            targetDate: $request->target_date->format('Y-m-d'),
            leaveType: (string) $request->leave_type,
            hours: $request->hours !== null ? (float) $request->hours : null,
            requestedDays: (float) $request->requested_days,
            approverUserId: $request->approver_user_id !== null ? (string) $request->approver_user_id : null,
            reason: $request->reason,
            requestGroupId: $request->request_group_id,
            workflowRequestId: $workflowRequestId,
            status: $status,
            usageId: $usageId,
            hasUsage: $usageId !== null,
            submittedAt: $request->submitted_at?->format('Y-m-d H:i:s'),
            approvedAt: $request->approved_at?->format('Y-m-d H:i:s'),
            returnedAt: $request->returned_at?->format('Y-m-d H:i:s'),
            cancelledAt: $request->cancelled_at?->format('Y-m-d H:i:s'),
        );

        return ['command' => $command, 'rejected' => $rejected, 'cancelUsage' => $cancelUsage, 'warning' => $warning];
    }

    /** 申請に対応するワークフローID(休暇申請文脈の対応表。無ければnull)。 */
    private function workflowRequestIdOf(string $requestId): ?string
    {
        $workflowRequestId = LeaveRequestWorkflowLink::query()
            ->where('leave_request_id', $requestId)
            ->where('leave_kind', LeaveRequestWorkflowLink::KIND_PAID)
            ->value('workflow_request_id');

        return $workflowRequestId === null ? null : (string) $workflowRequestId;
    }
}
