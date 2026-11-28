<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ShiftSwap\Commands\CancelShiftSwapRequest;
use App\Domain\ShiftSwap\Commands\RequestShiftSwap;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftSwapRequestResource;
use App\Models\ShiftSwapRequest;
use App\Models\ShiftSwapRequestStatus;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * 振替休日の申請・承認。特別休暇(SpecialLeaveController)と同じ申請・承認のUXだが、
 * ビジネスロジックは完全に独立したドメイン(App\Domain\ShiftSwap)として実装する。
 */
#[OA\Tag(name: '振替休日', description: '振替休日申請・承認')]
class ShiftSwapRequestController extends Controller
{
    /**
     * 振替休日を申請する(承認不要の申請)。`RequestShiftSwap`を1つdispatchするだけで、承認の要否は読まない。
     * 承認が必要な設定ではHandlerが422で拒否する(承認が必要な申請は POST /shift-swap/requests/workflow)。
     */
    #[OA\Post(
        path: '/shift-swap/requests',
        operationId: 'shiftSwap.requests.store',
        summary: '振替休日を申請する(承認不要の設定)',
        description: '承認不要の設定(公開設定のshift_swap_requires_approval=false)のときに使う。承認が必要な設定では422(承認が必要です)になるため、POST /shift-swap/requests/workflow を使う。',
        tags: ['振替休日'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_date', 'substitute_date'], properties: [new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'substitute_date', type: 'string', format: 'date'), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $this->validateStoreRequest($request, approverRequired: false);

        $shiftSwapRequest = $commandBus->dispatch(new RequestShiftSwap(
            userId: $request->user()->id,
            targetDate: $data['target_date'],
            substituteDate: $data['substitute_date'],
            approverUserId: $data['approver_user_id'] ?? null,
            reason: $data['reason'] ?? null,
            workflowRequestId: null,
            requestId: (string) Str::uuid(),
        ));

        return (new ShiftSwapRequestResource($shiftSwapRequest->load('user', 'approver')))->response()->setStatusCode(201);
    }

    /**
     * 振替休日を申請する(承認が必要な申請)。`DraftWorkflowRequest`を1つdispatchするだけで、承認の要否は読まない。
     * 承認不要の設定ではワークフローの下書きのHandlerが422で拒否する(POST /shift-swap/requests を使う)。
     */
    #[OA\Post(
        path: '/shift-swap/requests/workflow',
        operationId: 'shiftSwap.requests.storeWorkflow',
        summary: '振替休日を申請する(承認が必要な設定。承認者を指定してワークフローを作る)',
        description: '承認が必要な設定(公開設定のshift_swap_requires_approval=true)のときに使う。承認不要の設定では422(承認が不要です)になるため、POST /shift-swap/requests を使う。',
        tags: ['振替休日'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_date', 'substitute_date', 'approver_user_id'], properties: [new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'substitute_date', type: 'string', format: 'date'), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeWorkflowRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $this->validateStoreRequest($request, approverRequired: true);

        // 振替休日申請はworkflow_requestの下書き作成を起点にする。ShiftSwapRequest集約への
        // RequestShiftSwapはShiftSwapRequestOnWorkflowRequestDraftedReactorが発行する
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
        // ShiftSwapRequestのIDはここで採番してsubjectIdとして渡す。Handler側から
        // workflow_requests.subject_idを直接書き換えるとProjectionの再生成で失われるため
        // (ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
        $requestId = (string) Str::uuid();

        $commandBus->dispatch(new DraftWorkflowRequest(
            requestTypeCode: null,
            applicantUserId: $request->user()->id,
            title: $data['target_date'].' の振替休日申請',
            formData: [
                'target_date' => $data['target_date'],
                'substitute_date' => $data['substitute_date'],
                'reason' => $data['reason'] ?? null,
            ],
            approverUserId: $data['approver_user_id'],
            subjectType: WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST,
            subjectId: $requestId,
        ));

        $shiftSwapRequest = ShiftSwapRequest::query()->findOrFail($requestId);

        return (new ShiftSwapRequestResource($shiftSwapRequest->load('user', 'approver')))->response()->setStatusCode(201);
    }

    /**
     * 振替休日申請の入力の検証(形式のみ)。承認者の指定は、承認が必要な入口でだけ必須にする(承認の要否は読まない)。
     *
     * @return array<string, mixed>
     */
    private function validateStoreRequest(Request $request, bool $approverRequired): array
    {
        return $request->validate([
            'target_date' => ['required', 'date'],
            'substitute_date' => ['required', 'date'],
            'approver_user_id' => [$approverRequired ? 'required' : 'nullable', 'string', 'exists:users,id'],
            'reason' => ['nullable', 'string'],
        ]);
    }

    #[OA\Get(
        path: '/shift-swap/requests/mine',
        operationId: 'shiftSwap.requests.mine',
        summary: '自分の振替休日申請一覧を取得する',
        tags: ['振替休日'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $requests = ShiftSwapRequest::query()
            ->with('user', 'approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('target_date')
            ->get();

        return ShiftSwapRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/shift-swap/requests/to-approve',
        operationId: 'shiftSwap.requests.toApprove',
        summary: '承認待ち振替休日申請一覧を取得する',
        tags: ['振替休日'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function requestsToApprove(Request $request): AnonymousResourceCollection
    {
        $requests = ShiftSwapRequest::query()
            ->with('user', 'approver')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', ShiftSwapRequestStatus::SUBMITTED)
            ->orderBy('target_date')
            ->get();

        return ShiftSwapRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/shift-swap/requests/{shiftSwapRequest}',
        operationId: 'shiftSwap.requests.show',
        summary: '振替休日申請の詳細を取得する',
        tags: ['振替休日'],
        parameters: [new OA\Parameter(name: 'shiftSwapRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(ShiftSwapRequest $shiftSwapRequest): ShiftSwapRequestResource
    {
        return new ShiftSwapRequestResource($shiftSwapRequest->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/shift-swap/requests/{shiftSwapRequest}/approve',
        operationId: 'shiftSwap.requests.approve',
        summary: '振替休日申請を承認する',
        tags: ['振替休日'],
        parameters: [new OA\Parameter(name: 'shiftSwapRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveRequest(Request $request, ShiftSwapRequest $shiftSwapRequest, CommandBus $commandBus): ShiftSwapRequestResource
    {
        // 承認はworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ApproveWorkflowRequestを発行する。
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $shiftSwapRequest,
                '対応する申請が見つからないため承認できません。',
            ),
            approvedByUserId: $request->user()->id,
        ));

        return new ShiftSwapRequestResource($shiftSwapRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/shift-swap/requests/{shiftSwapRequest}/return',
        operationId: 'shiftSwap.requests.return',
        summary: '振替休日申請を差し戻す',
        tags: ['振替休日'],
        parameters: [new OA\Parameter(name: 'shiftSwapRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnRequest(Request $request, ShiftSwapRequest $shiftSwapRequest, CommandBus $commandBus): ShiftSwapRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        // 差戻しはworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ReturnWorkflowRequestを発行する。
        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $shiftSwapRequest,
                '対応する申請が見つからないため差し戻せません。',
            ),
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new ShiftSwapRequestResource($shiftSwapRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/shift-swap/requests/{shiftSwapRequest}/cancel',
        operationId: 'shiftSwap.requests.cancel',
        summary: '振替休日申請を取り消す',
        tags: ['振替休日'],
        parameters: [new OA\Parameter(name: 'shiftSwapRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, ShiftSwapRequest $shiftSwapRequest, CommandBus $commandBus): ShiftSwapRequestResource
    {
        $commandBus->dispatch(new CancelShiftSwapRequest($shiftSwapRequest->id, $request->user()->id));

        return new ShiftSwapRequestResource($shiftSwapRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 承認・差戻し対象のworkflow_request(subject_type=shift_swap_request)を特定する。
     * 見つからない場合に黙って何もしないと、状態が変わらないまま200を返してしまうため
     * DomainRuleExceptionを投げる。
     */
    private function submittedWorkflowRequestId(ShiftSwapRequest $shiftSwapRequest, string $message): string
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST)
            ->where('subject_id', $shiftSwapRequest->id)
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->latest()
            ->first();

        if ($workflowRequest === null) {
            throw new DomainRuleException($message);
        }

        return $workflowRequest->id;
    }
}
