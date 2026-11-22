<?php

namespace App\Domain\BackOffice\Support;

use App\Models\BackOfficeTask;
use App\Models\WorkflowRequest;

/**
 * バックオフィスタスクのステータス遷移の許可判定(例外を投げない)。
 * 遷移の許可は発生源(`workflow_requests.request_type_id`)の`allowed_status_transitions`マスタで定義する
 * (docs/11-usecases-backoffice.md「実装上のポイント」)。未設定(null)の申請種別、または
 * workflow_request以外が発生源のタスクは制限なしとする。
 */
class BackOfficeTaskTransitionPolicy
{
    /**
     * `$task->source` は呼び出し元で `with('source')` 読み込み済みであること。
     */
    public function isAllowed(BackOfficeTask $task, string $newStatus): bool
    {
        if ($task->status === $newStatus) {
            return true;
        }

        if (! $task->source instanceof WorkflowRequest) {
            return true;
        }

        $transitions = $task->source->requestType?->allowed_status_transitions;
        if ($transitions === null) {
            return true;
        }

        $allowedNextStatuses = $transitions[$task->status] ?? [];

        return in_array($newStatus, $allowedNextStatuses, true);
    }
}
