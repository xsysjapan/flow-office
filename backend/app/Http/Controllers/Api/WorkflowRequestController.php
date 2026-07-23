<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Support\EventHistoryQuery;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoredEventResource;
use App\Http\Resources\WorkflowRequestResource;
use App\Models\WorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-W002〜UC-W005: 汎用申請の作成・提出・承認・差戻し・取消。
 */
#[OA\Tag(name: '汎用申請', description: '申請の作成・提出・承認・差戻し・取消')]
class WorkflowRequestController extends Controller
{
    #[OA\Get(
        path: '/workflow-requests/mine',
        operationId: 'workflowRequests.mine',
        summary: '自分の申請一覧を取得する',
        tags: ['汎用申請'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $requests = WorkflowRequest::query()
            ->with(['requestType', 'applicant', 'approver'])
            ->where('applicant_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return WorkflowRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/workflow-requests/to-approve',
        operationId: 'workflowRequests.toApprove',
        summary: '承認待ち申請一覧を取得する',
        tags: ['汎用申請'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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

    #[OA\Get(
        path: '/workflow-requests/{workflowRequest}',
        operationId: 'workflowRequests.show',
        summary: '申請詳細を取得する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(Request $request, WorkflowRequest $workflowRequest): WorkflowRequestResource
    {
        return new WorkflowRequestResource(
            $workflowRequest->load(['requestType', 'applicant', 'approver', 'attachments'])
        );
    }

    #[OA\Post(
        path: '/workflow-requests',
        operationId: 'workflowRequests.store',
        summary: '申請の下書きを作成する',
        tags: ['汎用申請'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['request_type_code', 'title', 'form_data'], properties: [new OA\Property(property: 'request_type_code', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'form_data', type: 'object'), new OA\Property(property: 'approver_user_id', type: 'integer', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
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

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/submit',
        operationId: 'workflowRequests.submit',
        summary: '申請を提出する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'approver_user_id', type: 'integer', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/approve',
        operationId: 'workflowRequests.approve',
        summary: '申請を承認する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approve(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            approvedByUserId: $request->user()->id,
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/return',
        operationId: 'workflowRequests.return',
        summary: '申請を差し戻す',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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
    #[OA\Get(
        path: '/workflow-requests/{workflowRequest}/history',
        operationId: 'workflowRequests.history',
        summary: '申請の履歴を取得する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function history(Request $request, WorkflowRequest $workflowRequest, EventHistoryQuery $historyQuery): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless(
            $user->id === $workflowRequest->applicant_user_id
                || $user->id === $workflowRequest->approver_user_id
                || $user->hasRole('admin'),
            403,
            'この申請の履歴を閲覧する権限がありません。'
        );

        $events = $historyQuery
            ->search(aggregateType: 'workflow_request', aggregateId: (string) $workflowRequest->id)
            ->sortBy('occurred_at')
            ->values();

        return StoredEventResource::collection($events);
    }

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/cancel',
        operationId: 'workflowRequests.cancel',
        summary: '申請を取り消す',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
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
