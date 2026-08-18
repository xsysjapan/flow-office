<?php

namespace App\Http\Controllers\Api;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveGrantCancellation;
use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest as ApproveCompensatoryLeaveRequestCommand;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveGrant;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveRequest;
use App\Domain\CompensatoryLeave\Commands\GrantCompensatoryLeave;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeaveGrantCancellation;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Leave\Support\LeaveHistoryQuery;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompensatoryLeaveGrantResource;
use App\Http\Resources\CompensatoryLeaveRequestResource;
use App\Http\Resources\CompensatoryLeaveUsageResource;
use App\Http\Resources\StoredEventResource;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\CompensatoryLeaveUsage;
use App\Models\PaidLeaveType;
use App\Models\SystemSetting;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * 代休の残数管理・消化申請・承認。付与(Grant)は休日出勤の勤怠実績から自動導出されるため
 * (App\Domain\CompensatoryLeave参照)、このControllerでは付与のCRUDは提供しない。
 * 特別休暇(SpecialLeaveController)と同じ承認要否分岐パターンを踏襲する。
 */
#[OA\Tag(name: '代休', description: '代休の残数・消化申請・承認')]
class CompensatoryLeaveController extends Controller
{
    #[OA\Get(
        path: '/compensatory-leave/grants/mine',
        operationId: 'compensatoryLeave.grants.mine',
        summary: '自分の代休残数を取得する',
        tags: ['代休'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myGrants(Request $request): AnonymousResourceCollection
    {
        $grants = CompensatoryLeaveGrant::query()
            ->where('user_id', $request->user()->id)
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        return CompensatoryLeaveGrantResource::collection($grants);
    }

    #[OA\Get(
        path: '/compensatory-leave/grants/user/{userId}',
        operationId: 'compensatoryLeave.grants.forUser',
        summary: '社員の代休残数を取得する',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function grantsForUser(string $userId): AnonymousResourceCollection
    {
        $grants = CompensatoryLeaveGrant::query()
            ->where('user_id', $userId)
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        return CompensatoryLeaveGrantResource::collection($grants);
    }

    /**
     * 管理者が、休日出勤の対象日(workDate)を指定して代休を手動付与する
     * (App\Domain\CompensatoryLeave\Handlers\GrantCompensatoryLeaveHandler参照。
     * 付与日数は勤怠実績からの自動導出と同じルールで算出され、承認不要でstatus=confirmedの
     * まま作成される)。
     */
    #[OA\Post(
        path: '/compensatory-leave/grants',
        operationId: 'compensatoryLeave.grants.store',
        summary: '代休を手動付与する',
        tags: ['代休'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'work_date'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_date', type: 'string', format: 'date', description: '実際に休日出勤した日'), new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'grant_reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function grant(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'expires_on' => ['nullable', 'date', 'after:work_date'],
            'grant_reason' => ['nullable', 'string'],
        ]);

        $grant = $commandBus->dispatch(new GrantCompensatoryLeave(
            userId: $data['user_id'],
            workDate: $data['work_date'],
            expiresOn: $data['expires_on'] ?? null,
            grantReason: $data['grant_reason'] ?? null,
        ));

        return (new CompensatoryLeaveGrantResource($grant))->response()->setStatusCode(201);
    }

    /**
     * 管理者が代休Grantを直接取り消す(承認フローを経由しない)。source(attendance/manual)を
     * 問わず利用できる。既存のrequest-cancellation→approve(社員起点の申請→承認)フローは
     * そのまま残し、こちらは管理者起点の別経路として提供する。既存の
     * CancelCompensatoryLeaveGrantHandlerがused_days>0の場合にDomainRuleExceptionを投げる。
     */
    #[OA\Post(
        path: '/compensatory-leave/grants/{grant}/revoke',
        operationId: 'compensatoryLeave.grants.revoke',
        summary: '代休付与を直接取り消す(承認不要)',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'grant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function revoke(Request $request, CompensatoryLeaveGrant $grant, CommandBus $commandBus): CompensatoryLeaveGrantResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $grant = $commandBus->dispatch(new CancelCompensatoryLeaveGrant(
            grantId: $grant->id,
            cancelledByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        return new CompensatoryLeaveGrantResource($grant);
    }

    #[OA\Post(
        path: '/compensatory-leave/requests',
        operationId: 'compensatoryLeave.requests.store',
        summary: '代休を消化申請する',
        tags: ['代休'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_date', 'leave_type'], properties: [new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        // system_settings.compensatory_leave_requires_approval=falseの場合、承認ワークフローを
        // 経由せずその場で申請→承認不要のまま(消化)まで完結させる(SpecialLeaveController::
        // storeRequestと同じ考え方)。
        $requiresApproval = SystemSetting::current()->compensatory_leave_requires_approval;

        $data = $request->validate([
            'target_date' => ['required', 'date'],
            'leave_type' => ['required', Rule::in(PaidLeaveType::values())],
            'hours' => ['nullable', 'numeric', 'min:0.5'],
            'approver_user_id' => [$requiresApproval ? 'required' : 'nullable', 'string', 'exists:users,id'],
            'reason' => ['nullable', 'string'],
            // 期間指定でまとめて申請した複数日分(1日1リクエスト)を束ねるID。frontend側が
            // 同一の申請操作内で生成した同じ値を全日分に渡す(単日申請では省略)。
            'request_group_id' => ['nullable', 'string', 'uuid'],
        ]);

        if ($requiresApproval) {
            // UC-P004相当: 代休申請はworkflow_requestの下書き作成を起点にする。
            // CompensatoryLeaveRequest集約へのRequestCompensatoryLeaveは
            // CompensatoryLeaveRequestOnWorkflowRequestDraftedReactorが発行する
            // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
            $requestId = (string) Str::uuid();

            $commandBus->dispatch(new DraftWorkflowRequest(
                requestTypeCode: null,
                applicantUserId: $request->user()->id,
                title: $data['target_date'].' の代休申請',
                formData: [
                    'target_date' => $data['target_date'],
                    'leave_type' => $data['leave_type'],
                    'hours' => $data['hours'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'request_group_id' => $data['request_group_id'] ?? null,
                ],
                approverUserId: $data['approver_user_id'],
                subjectType: WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST,
                subjectId: $requestId,
            ));

            $compensatoryLeaveRequest = CompensatoryLeaveRequest::query()->findOrFail($requestId);

            return (new CompensatoryLeaveRequestResource($compensatoryLeaveRequest->load('user', 'approver')))->response()->setStatusCode(201);
        }

        // 承認不要設定: workflow_requestを作らず、RequestCompensatoryLeave→
        // ApproveCompensatoryLeaveRequest(approvedByUserId: null)を同一トランザクションで発行し、
        // その場で消化まで確定させる(SpecialLeaveController::storeRequestと同様)。
        $requestId = (string) Str::uuid();
        $approverUserId = $data['approver_user_id'] ?? $request->user()->id;

        $compensatoryLeaveRequest = DB::transaction(function () use ($commandBus, $data, $requestId, $approverUserId, $request) {
            $commandBus->dispatch(new RequestCompensatoryLeave(
                userId: $request->user()->id,
                targetDate: $data['target_date'],
                leaveType: $data['leave_type'],
                hours: $data['hours'] ?? null,
                approverUserId: $approverUserId,
                reason: $data['reason'] ?? null,
                workflowRequestId: null,
                requestId: $requestId,
                requestGroupId: $data['request_group_id'] ?? null,
            ));

            return $commandBus->dispatch(new ApproveCompensatoryLeaveRequestCommand(
                compensatoryLeaveRequestId: $requestId,
                approvedByUserId: null,
            ));
        });

        return (new CompensatoryLeaveRequestResource($compensatoryLeaveRequest->load('user', 'approver')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/compensatory-leave/requests/mine',
        operationId: 'compensatoryLeave.requests.mine',
        summary: '自分の代休申請一覧を取得する',
        tags: ['代休'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $requests = CompensatoryLeaveRequest::query()
            ->with('user', 'approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('target_date')
            ->get();

        return CompensatoryLeaveRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/compensatory-leave/requests/to-approve',
        operationId: 'compensatoryLeave.requests.toApprove',
        summary: '承認待ち代休申請一覧を取得する',
        tags: ['代休'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function requestsToApprove(Request $request): AnonymousResourceCollection
    {
        $requests = CompensatoryLeaveRequest::query()
            ->with('user', 'approver')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', CompensatoryLeaveRequestStatus::SUBMITTED)
            ->orderBy('target_date')
            ->get();

        return CompensatoryLeaveRequestResource::collection($requests);
    }

    #[OA\Post(
        path: '/compensatory-leave/requests/{compensatoryLeaveRequest}/approve',
        operationId: 'compensatoryLeave.requests.approve',
        summary: '代休申請を承認する',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'compensatoryLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveRequest(Request $request, CompensatoryLeaveRequest $compensatoryLeaveRequest, CommandBus $commandBus): CompensatoryLeaveRequestResource
    {
        // UC-P004相当: 承認はworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ApproveWorkflowRequestを発行する。
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $compensatoryLeaveRequest,
                '対応する申請が見つからないため承認できません。',
            ),
            approvedByUserId: $request->user()->id,
        ));

        return new CompensatoryLeaveRequestResource($compensatoryLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/compensatory-leave/requests/{compensatoryLeaveRequest}/return',
        operationId: 'compensatoryLeave.requests.return',
        summary: '代休申請を差し戻す',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'compensatoryLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnRequest(Request $request, CompensatoryLeaveRequest $compensatoryLeaveRequest, CommandBus $commandBus): CompensatoryLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        // UC-P004相当 手順2: 差戻しはworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ReturnWorkflowRequestを発行する。
        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $compensatoryLeaveRequest,
                '対応する申請が見つからないため差し戻せません。',
            ),
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new CompensatoryLeaveRequestResource($compensatoryLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/compensatory-leave/requests/{compensatoryLeaveRequest}/cancel',
        operationId: 'compensatoryLeave.requests.cancel',
        summary: '代休申請を取り消す',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'compensatoryLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, CompensatoryLeaveRequest $compensatoryLeaveRequest, CommandBus $commandBus): CompensatoryLeaveRequestResource
    {
        $commandBus->dispatch(new CancelCompensatoryLeaveRequest($compensatoryLeaveRequest->id, $request->user()->id));

        return new CompensatoryLeaveRequestResource($compensatoryLeaveRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 管理者が対象社員の代休申請を取り消す(自分の申請のみ取消可能な`cancelRequest`とは別に、
     * 管理者は他者の承認済み申請も取り消せる。cancelledByUserIdは申請者本人ではなく操作者
     * (管理者)のIDを渡す)。
     */
    #[OA\Post(
        path: '/compensatory-leave/requests/{compensatoryLeaveRequest}/admin-cancel',
        operationId: 'compensatoryLeave.requests.adminCancel',
        summary: '管理者が社員の代休申請を取り消す',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'compensatoryLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function adminCancelRequest(Request $request, CompensatoryLeaveRequest $compensatoryLeaveRequest, CommandBus $commandBus): CompensatoryLeaveRequestResource
    {
        $commandBus->dispatch(new CancelCompensatoryLeaveRequest($compensatoryLeaveRequest->id, $request->user()->id, isAdminAction: true));

        return new CompensatoryLeaveRequestResource($compensatoryLeaveRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 管理者が対象社員の代休消化明細(compensatory_leave_usages)を確認する。取消は明細単位
     * ではできず、明細に紐づく申請を`adminCancelRequest`で取り消すことで反映される。
     */
    #[OA\Get(
        path: '/compensatory-leave/usages/user/{userId}',
        operationId: 'compensatoryLeave.usages.forUser',
        summary: '社員の代休消化明細を取得する',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function usagesForUser(string $userId): AnonymousResourceCollection
    {
        $usages = CompensatoryLeaveUsage::query()
            ->with('request')
            ->where('user_id', $userId)
            ->orderByDesc('used_on')
            ->get();

        return CompensatoryLeaveUsageResource::collection($usages);
    }

    #[OA\Post(
        path: '/compensatory-leave/grants/{grant}/request-cancellation',
        operationId: 'compensatoryLeave.grants.requestCancellation',
        summary: '未使用の確定済み代休の取消を申請する',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'grant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function requestGrantCancellation(Request $request, CompensatoryLeaveGrant $grant, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $result = $commandBus->dispatch(new RequestCompensatoryLeaveGrantCancellation(
            grantId: $grant->id,
            requestedByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        // 承認不要設定時はその場でGrantが取消確定される。承認要の場合は
        // compensatory_leave_grant_cancellationsの申請行(pending)が返る。
        if ($result instanceof CompensatoryLeaveGrant) {
            return (new CompensatoryLeaveGrantResource($result))->response();
        }

        return response()->json([
            'id' => $result->id,
            'grant_id' => $result->grant_id,
            'status' => $result->status,
            'reason' => $result->reason,
        ]);
    }

    #[OA\Post(
        path: '/compensatory-leave/grant-cancellations/{cancellationId}/approve',
        operationId: 'compensatoryLeave.grantCancellations.approve',
        summary: '代休の取消申請を承認する',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'cancellationId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveGrantCancellation(Request $request, int $cancellationId, CommandBus $commandBus): JsonResponse
    {
        $cancellation = $commandBus->dispatch(new ApproveCompensatoryLeaveGrantCancellation(
            cancellationId: $cancellationId,
            approvedByUserId: $request->user()->id,
        ));

        return response()->json([
            'id' => $cancellation->id,
            'grant_id' => $cancellation->grant_id,
            'status' => $cancellation->status,
            'approved_at' => $cancellation->approved_at?->toIso8601String(),
        ]);
    }

    /**
     * 自分の代休履歴を確認する。EventStore(stored_events)を正の記録として直接検索する
     * (付与・申請・承認・差戻し・取消のすべてを時系列で表示するため、現残高スナップショット
     * のみを返す`myGrants`とは別に用意する。paid-leave/special-leaveと同じ考え方)。
     */
    #[OA\Get(
        path: '/compensatory-leave/history/mine',
        operationId: 'compensatoryLeave.history.mine',
        summary: '自分の代休履歴を取得する',
        tags: ['代休'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myHistory(Request $request): AnonymousResourceCollection
    {
        return $this->historyResponse($request->user()->id);
    }

    /**
     * 管理者・人事担当者が対象社員の代休履歴を確認する。他の管理者向けエンドポイント
     * (`grantsForUser`等)と同様、認可はルート側のPermissionで行う。
     */
    #[OA\Get(
        path: '/compensatory-leave/history/user/{userId}',
        operationId: 'compensatoryLeave.history.forUser',
        summary: '社員の代休履歴を取得する',
        tags: ['代休'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function historyForUser(string $userId): AnonymousResourceCollection
    {
        return $this->historyResponse($userId);
    }

    /**
     * 承認・差戻し対象のworkflow_request(subject_type=compensatory_leave_request)を特定する。
     * 見つからない場合に黙って何もしないと、状態が変わらないまま200を返してしまうため
     * DomainRuleExceptionを投げる。
     */
    private function submittedWorkflowRequestId(CompensatoryLeaveRequest $compensatoryLeaveRequest, string $message): string
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST)
            ->where('subject_id', $compensatoryLeaveRequest->id)
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->latest()
            ->first();

        if ($workflowRequest === null) {
            throw new DomainRuleException($message);
        }

        return $workflowRequest->id;
    }

    /**
     * `compensatory_leave_grant`/`compensatory_leave_request`それぞれの集約に属するイベントを
     * 時系列で返す(LeaveHistoryQuery参照。有給・特別休暇で共通の読み取り専用Query)。
     */
    private function historyResponse(string $userId): AnonymousResourceCollection
    {
        $events = LeaveHistoryQuery::eventsForUser(
            userId: $userId,
            grantModelClass: CompensatoryLeaveGrant::class,
            requestModelClass: CompensatoryLeaveRequest::class,
        );

        return StoredEventResource::collection($events);
    }
}
