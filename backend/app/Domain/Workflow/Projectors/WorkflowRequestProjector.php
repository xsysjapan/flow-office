<?php

namespace App\Domain\Workflow\Projectors;

use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\StoredEvent;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Support\Facades\DB;

/**
 * workflow_request.* イベントから workflow_requests を作成・更新する。
 *
 * 主キーがUUID(コマンド側生成)であるため、行の新規作成(drafted)自体も
 * このProjectorが担う。DB採番PKだと集約IDが確定するまでイベントを書けず
 * Projector化できないが、UUIDならその制約がない
 * (.claude/skills/add-projection「集約ルートのUUID化」参照)。
 */
class WorkflowRequestProjector implements Projector
{
    public function eventTypes(): array
    {
        return [
            'workflow_request.drafted',
            'workflow_request.submitted',
            'workflow_request.approved',
            'workflow_request.returned',
            'workflow_request.cancelled',
        ];
    }

    public function project(StoredEvent $event): void
    {
        $payload = $event->payload;
        $id = $payload['workflow_request_id'];

        match ($event->event_type) {
            'workflow_request.drafted' => WorkflowRequest::query()->updateOrCreate(
                ['id' => $id],
                [
                    'request_type_id' => $payload['request_type_id'],
                    'title' => $payload['title'],
                    'applicant_user_id' => $payload['applicant_user_id'],
                    'approver_user_id' => $payload['approver_user_id'],
                    'status' => WorkflowRequestStatus::DRAFT,
                    'form_data' => $payload['form_data'],
                ],
            ),
            'workflow_request.submitted' => WorkflowRequest::query()->whereKey($id)->update([
                'approver_user_id' => $payload['approver_user_id'],
                'status' => WorkflowRequestStatus::SUBMITTED,
                'submitted_at' => $event->occurred_at,
            ]),
            'workflow_request.approved' => WorkflowRequest::query()->whereKey($id)->update([
                'status' => WorkflowRequestStatus::APPROVED,
                'approved_at' => $event->occurred_at,
            ]),
            'workflow_request.returned' => WorkflowRequest::query()->whereKey($id)->update([
                'status' => WorkflowRequestStatus::RETURNED,
                'returned_at' => $event->occurred_at,
            ]),
            'workflow_request.cancelled' => WorkflowRequest::query()->whereKey($id)->update([
                'status' => WorkflowRequestStatus::CANCELLED,
                'cancelled_at' => $event->occurred_at,
            ]),
            default => null,
        };
    }

    public function reset(): void
    {
        DB::table('workflow_requests')->truncate();
    }
}
