<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoredEventResource;
use App\Http\Resources\WorkflowRequestResource;
use App\Models\StoredEvent;
use App\Models\WorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * UC-W002〜UC-W005: 汎用申請の作成・提出・承認・差戻し・取消。
 */
class WorkflowRequestController extends Controller
{
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $requests = WorkflowRequest::query()
            ->with(['requestType', 'applicant', 'approver'])
            ->where('applicant_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return WorkflowRequestResource::collection($requests);
    }

    public function indexToApprove(Request $request): AnonymousResourceCollection
    {
        $requests = WorkflowRequest::query()
            ->with(['requestType', 'applicant', 'approver'])
            ->where('approver_user_id', $request->user()->id)
            ->where('status', 'submitted')
            ->latest()
            ->paginate(20);

        return WorkflowRequestResource::collection($requests);
    }

    public function show(Request $request, WorkflowRequest $workflowRequest): WorkflowRequestResource
    {
        return new WorkflowRequestResource(
            $workflowRequest->load(['requestType', 'applicant', 'approver', 'attachments'])
        );
    }

    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'request_type_code' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'form_data' => ['present', 'array'],
            'approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $workflowRequest = $commandBus->dispatch(new DraftWorkflowRequest(
            requestTypeCode: $data['request_type_code'],
            applicantUserId: $request->user()->id,
            title: $data['title'],
            formData: $data['form_data'],
            approverUserId: $data['approver_user_id'] ?? null,
        ));

        $resource = new WorkflowRequestResource($workflowRequest->load(['requestType', 'applicant', 'approver']));

        return $resource->response()->setStatusCode(201);
    }

    public function submit(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $data = $request->validate([
            'approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $commandBus->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            submittedByUserId: $request->user()->id,
            approverUserId: $data['approver_user_id'] ?? null,
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    public function approve(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            approvedByUserId: $request->user()->id,
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    public function return(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    /**
     * UC-W003/UC-W004 コメント履歴: この申請に関するstored_eventsを時系列で返す。
     * 申請者・承認者・管理者のみ閲覧可能(汎用監査ログAPIとは別に、資源に紐づけて認可する)。
     */
    public function history(Request $request, WorkflowRequest $workflowRequest): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless(
            $user->id === $workflowRequest->applicant_user_id
                || $user->id === $workflowRequest->approver_user_id
                || $user->hasRole('admin'),
            403,
            'この申請の履歴を閲覧する権限がありません。'
        );

        $events = StoredEvent::query()
            ->where('aggregate_type', 'workflow_request')
            ->where('aggregate_id', (string) $workflowRequest->id)
            ->orderBy('occurred_at')
            ->get();

        return StoredEventResource::collection($events);
    }

    public function cancel(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string']]);

        $commandBus->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            cancelledByUserId: $request->user()->id,
            reason: $data['reason'],
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }
}
