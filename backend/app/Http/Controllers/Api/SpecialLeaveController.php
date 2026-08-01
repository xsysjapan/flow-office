<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Leave\Support\LeaveHistoryQuery;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialLeaveGrantResource;
use App\Http\Resources\SpecialLeaveGrantRuleResource;
use App\Http\Resources\SpecialLeaveRequestResource;
use App\Http\Resources\SpecialLeaveTypeResource;
use App\Http\Resources\StoredEventResource;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantRule;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveType;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * 特別休暇の種別マスタ・残数管理・申請・承認。有給休暇(PaidLeaveController)と同じ
 * 申請・承認・消化のUXだが、ビジネスロジックは完全に独立したドメイン
 * (App\Domain\SpecialLeave)として実装する。
 */
#[OA\Tag(name: '特別休暇', description: '特別休暇種別・付与・申請・承認')]
class SpecialLeaveController extends Controller
{
    #[OA\Get(
        path: '/special-leave/types',
        operationId: 'specialLeave.types.index',
        summary: '特別休暇種別一覧を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexTypes(): AnonymousResourceCollection
    {
        return SpecialLeaveTypeResource::collection(SpecialLeaveType::query()->orderBy('name')->get());
    }

    #[OA\Post(
        path: '/special-leave/types',
        operationId: 'specialLeave.types.store',
        summary: '特別休暇種別を作成する',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'is_active', type: 'boolean')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $type = SpecialLeaveType::query()->create(['is_active' => true, ...$data]);

        return (new SpecialLeaveTypeResource($type))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/special-leave/types/{specialLeaveType}',
        operationId: 'specialLeave.types.update',
        summary: '特別休暇種別を更新する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveType', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'is_active', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function updateType(Request $request, SpecialLeaveType $specialLeaveType): SpecialLeaveTypeResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $specialLeaveType->update($data);

        return new SpecialLeaveTypeResource($specialLeaveType);
    }

    #[OA\Get(
        path: '/special-leave/grant-rules',
        operationId: 'specialLeave.grantRules.index',
        summary: '特別休暇付与ルール一覧を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexRules(): AnonymousResourceCollection
    {
        return SpecialLeaveGrantRuleResource::collection(
            SpecialLeaveGrantRule::query()->with('steps', 'specialLeaveType')->orderBy('name')->get()
        );
    }

    #[OA\Post(
        path: '/special-leave/grant-rules',
        operationId: 'specialLeave.grantRules.store',
        summary: '特別休暇付与ルールを作成する',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['special_leave_type_id', 'name'], properties: [new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'work_style_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'min_attendance_rate', type: 'integer'), new OA\Property(property: 'first_grant_after_months', type: 'integer'), new OA\Property(property: 'grant_cycle_months', type: 'integer'), new OA\Property(property: 'expires_after_months', type: 'integer', nullable: true), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'special_leave_type_id' => ['required', 'integer', 'exists:special_leave_types,id'],
            'name' => ['required', 'string', 'max:100'],
            'work_style_id' => ['nullable', 'string', 'exists:work_styles,id'],
            'min_attendance_rate' => ['integer', 'between:0,100'],
            'first_grant_after_months' => ['integer', 'min:0'],
            'grant_cycle_months' => ['integer', 'min:1'],
            'expires_after_months' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'steps' => ['array'],
            'steps.*.continuous_service_months' => ['required', 'integer', 'min:0'],
            'steps.*.grant_days' => ['required', 'integer', 'min:0'],
        ]);

        $rule = SpecialLeaveGrantRule::query()->create($data);

        foreach ($data['steps'] ?? [] as $step) {
            $rule->steps()->create($step);
        }

        return (new SpecialLeaveGrantRuleResource($rule->load('steps', 'specialLeaveType')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/special-leave/grants/mine',
        operationId: 'specialLeave.grants.mine',
        summary: '自分の特別休暇残数を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myGrants(Request $request): AnonymousResourceCollection
    {
        $grants = SpecialLeaveGrant::query()
            ->with('specialLeaveType')
            ->where('user_id', $request->user()->id)
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        return SpecialLeaveGrantResource::collection($grants);
    }

    #[OA\Get(
        path: '/special-leave/grants/user/{userId}',
        operationId: 'specialLeave.grants.forUser',
        summary: '社員の特別休暇残数を取得する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function grantsForUser(string $userId): AnonymousResourceCollection
    {
        $grants = SpecialLeaveGrant::query()
            ->with('specialLeaveType')
            ->where('user_id', $userId)
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        return SpecialLeaveGrantResource::collection($grants);
    }

    #[OA\Post(
        path: '/special-leave/grants',
        operationId: 'specialLeave.grants.store',
        summary: '特別休暇を手動付与する',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'special_leave_type_id', 'granted_on', 'granted_days'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'granted_on', type: 'string', format: 'date'), new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'grant_reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function grant(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'special_leave_type_id' => ['required', 'integer', 'exists:special_leave_types,id'],
            'granted_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date', 'after:granted_on'],
            'granted_days' => ['required', 'numeric', 'min:0.5'],
            'grant_reason' => ['nullable', 'string'],
        ]);

        $grant = $commandBus->dispatch(new GrantSpecialLeave(
            userId: $data['user_id'],
            specialLeaveTypeId: $data['special_leave_type_id'],
            grantedOn: $data['granted_on'],
            expiresOn: $data['expires_on'] ?? null,
            grantedDays: (float) $data['granted_days'],
            grantReason: $data['grant_reason'] ?? null,
        ));

        return (new SpecialLeaveGrantResource($grant->load('specialLeaveType')))->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/special-leave/requests',
        operationId: 'specialLeave.requests.store',
        summary: '特別休暇を申請する',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['special_leave_type_id', 'target_date', 'leave_type', 'approver_user_id'], properties: [new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'special_leave_type_id' => ['required', 'integer', 'exists:special_leave_types,id'],
            'target_date' => ['required', 'date'],
            'leave_type' => ['required', Rule::in(PaidLeaveType::values())],
            'hours' => ['nullable', 'numeric', 'min:0.5'],
            'approver_user_id' => ['required', 'string', 'exists:users,id'],
            'reason' => ['nullable', 'string'],
        ]);

        // UC-P003: 特別休暇申請はworkflow_requestの下書き作成を起点にする。SpecialLeaveRequest集約への
        // RequestSpecialLeaveはSpecialLeaveRequestOnWorkflowRequestDraftedReactorが発行する
        // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
        // SpecialLeaveRequestのIDはここで採番してsubjectIdとして渡す。Handler側から
        // workflow_requests.subject_idを直接書き換えるとProjectionの再生成で失われるため
        // (ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
        $requestId = (string) Str::uuid();

        $commandBus->dispatch(new DraftWorkflowRequest(
            requestTypeCode: null,
            applicantUserId: $request->user()->id,
            title: $data['target_date'].' の特別休暇申請',
            formData: [
                'special_leave_type_id' => $data['special_leave_type_id'],
                'target_date' => $data['target_date'],
                'leave_type' => $data['leave_type'],
                'hours' => $data['hours'] ?? null,
                'reason' => $data['reason'] ?? null,
            ],
            approverUserId: $data['approver_user_id'],
            subjectType: WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST,
            subjectId: $requestId,
        ));

        $specialLeaveRequest = SpecialLeaveRequest::query()->findOrFail($requestId);

        return (new SpecialLeaveRequestResource($specialLeaveRequest->load('user', 'approver', 'specialLeaveType')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/special-leave/requests/mine',
        operationId: 'specialLeave.requests.mine',
        summary: '自分の特別休暇申請一覧を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $requests = SpecialLeaveRequest::query()
            ->with('user', 'approver', 'specialLeaveType')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('target_date')
            ->get();

        return SpecialLeaveRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/special-leave/requests/to-approve',
        operationId: 'specialLeave.requests.toApprove',
        summary: '承認待ち特別休暇申請一覧を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function requestsToApprove(Request $request): AnonymousResourceCollection
    {
        $requests = SpecialLeaveRequest::query()
            ->with('user', 'approver', 'specialLeaveType')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', SpecialLeaveRequestStatus::SUBMITTED)
            ->orderBy('target_date')
            ->get();

        return SpecialLeaveRequestResource::collection($requests);
    }

    #[OA\Post(
        path: '/special-leave/requests/{specialLeaveRequest}/approve',
        operationId: 'specialLeave.requests.approve',
        summary: '特別休暇申請を承認する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus): SpecialLeaveRequestResource
    {
        // UC-P004: 承認はworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ApproveWorkflowRequestを発行する。
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $specialLeaveRequest,
                '対応する申請が見つからないため承認できません。',
            ),
            approvedByUserId: $request->user()->id,
        ));

        return new SpecialLeaveRequestResource($specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType'));
    }

    #[OA\Post(
        path: '/special-leave/requests/{specialLeaveRequest}/return',
        operationId: 'specialLeave.requests.return',
        summary: '特別休暇申請を差し戻す',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus): SpecialLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        // UC-P004 手順2: 差戻しはworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ReturnWorkflowRequestを発行する。
        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $specialLeaveRequest,
                '対応する申請が見つからないため差し戻せません。',
            ),
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new SpecialLeaveRequestResource($specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType'));
    }

    #[OA\Post(
        path: '/special-leave/requests/{specialLeaveRequest}/cancel',
        operationId: 'specialLeave.requests.cancel',
        summary: '特別休暇申請を取り消す',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus): SpecialLeaveRequestResource
    {
        $commandBus->dispatch(new CancelSpecialLeaveRequest($specialLeaveRequest->id, $request->user()->id));

        return new SpecialLeaveRequestResource($specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType'));
    }

    #[OA\Get(
        path: '/special-leave/history/mine',
        operationId: 'specialLeave.history.mine',
        summary: '自分の特別休暇履歴を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myHistory(Request $request): AnonymousResourceCollection
    {
        return $this->historyResponse($request->user()->id);
    }

    #[OA\Get(
        path: '/special-leave/history/user/{userId}',
        operationId: 'specialLeave.history.forUser',
        summary: '社員の特別休暇履歴を取得する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function historyForUser(string $userId): AnonymousResourceCollection
    {
        return $this->historyResponse($userId);
    }

    /**
     * `special_leave_grant`/`special_leave_request` それぞれの集約に属するイベントを
     * 時系列で返す(LeaveHistoryQuery参照。有給・特別休暇で共通の読み取り専用Query)。
     */
    private function historyResponse(string $userId): AnonymousResourceCollection
    {
        $events = LeaveHistoryQuery::eventsForUser(
            userId: $userId,
            grantModelClass: SpecialLeaveGrant::class,
            requestModelClass: SpecialLeaveRequest::class,
        );

        return StoredEventResource::collection($events);
    }

    /**
     * 承認・差戻し対象のworkflow_request(subject_type=special_leave_request)を特定する。
     * 見つからない場合に黙って何もしないと、状態が変わらないまま200を返してしまうため
     * DomainRuleExceptionを投げる。
     */
    private function submittedWorkflowRequestId(SpecialLeaveRequest $specialLeaveRequest, string $message): string
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST)
            ->where('subject_id', $specialLeaveRequest->id)
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->latest()
            ->first();

        if ($workflowRequest === null) {
            throw new DomainRuleException($message);
        }

        return $workflowRequest->id;
    }
}
