<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Leave\Support\LeaveHistoryQuery;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest as ApprovePaidLeaveRequestCommand;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeave\Commands\RevokePaidLeaveGrant;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaidLeaveGrantResource;
use App\Http\Resources\PaidLeaveGrantRuleResource;
use App\Http\Resources\PaidLeaveRequestResource;
use App\Http\Resources\PaidLeaveUsageResource;
use App\Http\Resources\StoredEventResource;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\PaidLeaveUsage;
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
 * 有給残数管理・申請・承認 (docs/09-usecases-paid-leave.md UC-P001〜UC-P004, docs/21-mvp-scope.md)。
 * 継続勤務期間・出勤率に基づく自動付与バッチ、消滅警告、年5日取得義務警告は後続フェーズで実装する。
 */
#[OA\Tag(name: '有給休暇', description: '有給付与・申請・承認')]
class PaidLeaveController extends Controller
{
    #[OA\Get(
        path: '/paid-leave/grant-rules',
        operationId: 'paidLeave.grantRules.index',
        summary: '有給付与ルール一覧を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexRules(): AnonymousResourceCollection
    {
        return PaidLeaveGrantRuleResource::collection(
            PaidLeaveGrantRule::query()->with('steps')->orderBy('name')->get()
        );
    }

    #[OA\Post(
        path: '/paid-leave/grant-rules',
        operationId: 'paidLeave.grantRules.store',
        summary: '有給付与ルールを作成する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'work_style_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'min_attendance_rate', type: 'integer'), new OA\Property(property: 'first_grant_after_months', type: 'integer'), new OA\Property(property: 'grant_cycle_months', type: 'integer'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'work_style_id' => ['nullable', 'string', 'exists:work_styles,id'],
            'min_attendance_rate' => ['integer', 'between:0,100'],
            'first_grant_after_months' => ['integer', 'min:0'],
            'grant_cycle_months' => ['integer', 'min:1'],
            'is_active' => ['boolean'],
            'steps' => ['array'],
            'steps.*.continuous_service_months' => ['required', 'integer', 'min:0'],
            'steps.*.grant_days' => ['required', 'integer', 'min:0'],
        ]);

        $rule = PaidLeaveGrantRule::query()->create($data);

        foreach ($data['steps'] ?? [] as $step) {
            $rule->steps()->create($step);
        }

        return (new PaidLeaveGrantRuleResource($rule->load('steps')))->response()->setStatusCode(201);
    }

    /**
     * 有給残数を確認する (UC-A007 有給残数表示の元データ)。
     */
    #[OA\Get(
        path: '/paid-leave/grants/mine',
        operationId: 'paidLeave.grants.mine',
        summary: '自分の有給残数を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myGrants(Request $request): AnonymousResourceCollection
    {
        $grants = PaidLeaveGrant::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('expires_on')
            ->get();

        return PaidLeaveGrantResource::collection($grants);
    }

    #[OA\Get(
        path: '/paid-leave/grants/user/{userId}',
        operationId: 'paidLeave.grants.forUser',
        summary: '社員の有給残数を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function grantsForUser(string $userId): AnonymousResourceCollection
    {
        $grants = PaidLeaveGrant::query()
            ->where('user_id', $userId)
            ->orderBy('expires_on')
            ->get();

        return PaidLeaveGrantResource::collection($grants);
    }

    /**
     * UC-P002: 有給を付与する(人事担当者による手動実行)。
     */
    #[OA\Post(
        path: '/paid-leave/grants',
        operationId: 'paidLeave.grants.store',
        summary: '有給を手動付与する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'granted_on', 'expires_on', 'granted_days'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'granted_on', type: 'string', format: 'date'), new OA\Property(property: 'expires_on', type: 'string', format: 'date'), new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'grant_reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function grant(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'granted_on' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after:granted_on'],
            'granted_days' => ['required', 'numeric', 'min:0.5'],
            'grant_reason' => ['nullable', 'string'],
        ]);

        $grant = $commandBus->dispatch(new GrantPaidLeave(
            userId: $data['user_id'],
            grantedOn: $data['granted_on'],
            expiresOn: $data['expires_on'],
            grantedDays: (float) $data['granted_days'],
            grantReason: $data['grant_reason'] ?? null,
        ));

        return (new PaidLeaveGrantResource($grant))->response()->setStatusCode(201);
    }

    /**
     * 管理者が発行済みの有給付与を取り消す。既に消化された分がある場合は
     * RevokePaidLeaveGrantHandlerがDomainRuleExceptionを投げる(422)。
     */
    #[OA\Post(
        path: '/paid-leave/grants/{grant}/revoke',
        operationId: 'paidLeave.grants.revoke',
        summary: '有給付与を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'grant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function revoke(Request $request, PaidLeaveGrant $grant, CommandBus $commandBus): PaidLeaveGrantResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $grant = $commandBus->dispatch(new RevokePaidLeaveGrant(
            grantId: $grant->id,
            revokedByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        return new PaidLeaveGrantResource($grant);
    }

    /**
     * UC-P003: 有給を申請する。
     */
    #[OA\Post(
        path: '/paid-leave/requests',
        operationId: 'paidLeave.requests.store',
        summary: '有給を申請する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_date', 'leave_type', 'approver_user_id'], properties: [new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'reason', type: 'string', nullable: true), new OA\Property(property: 'request_group_id', type: 'string', format: 'uuid', nullable: true, description: '期間指定でまとめて申請した複数日分を束ねるID(単日申請では省略)')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        // system_settings.paid_leave_requires_approval=falseの場合、承認ワークフローを
        // 経由せずその場で申請→承認不要のまま(消化)まで完結させる(ルートCLAUDE.md
        // 「AIは勤怠ルールを決定しない」とは無関係の、承認要否そのものをマスタ化した設定)。
        $requiresApproval = SystemSetting::current()->paid_leave_requires_approval;

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
            // UC-P003: 有給申請はworkflow_requestの下書き作成を起点にする。PaidLeaveRequest集約への
            // RequestPaidLeaveはPaidLeaveRequestOnWorkflowRequestDraftedReactorが発行する
            // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
            // PaidLeaveRequestのIDはここで採番してsubjectIdとして渡す。Handler側から
            // workflow_requests.subject_idを直接書き換えるとProjectionの再生成で失われるため
            // (ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
            $requestId = (string) Str::uuid();

            $commandBus->dispatch(new DraftWorkflowRequest(
                requestTypeCode: null,
                applicantUserId: $request->user()->id,
                title: $data['target_date'].' の有給申請',
                formData: [
                    'target_date' => $data['target_date'],
                    'leave_type' => $data['leave_type'],
                    'hours' => $data['hours'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'request_group_id' => $data['request_group_id'] ?? null,
                ],
                approverUserId: $data['approver_user_id'],
                subjectType: WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST,
                subjectId: $requestId,
            ));

            $paidLeaveRequest = PaidLeaveRequest::query()->findOrFail($requestId);

            return (new PaidLeaveRequestResource($paidLeaveRequest->load('user', 'approver')))->response()->setStatusCode(201);
        }

        // 承認不要設定: workflow_requestを作らず、RequestPaidLeave→ApprovePaidLeaveRequest
        // (approvedByUserId: null)を同一トランザクションで発行し、その場で消化まで確定させる。
        // RequestPaidLeaveAggregate::request()のapproverUserIdは非null必須のため、
        // 指定が無い場合は申請者自身のIDをプレースホルダとして使う(このパスの申請は
        // 即座にapprovedになるため、requests/to-approve一覧(status=submittedのみ表示)には
        // そもそも現れず、実質的な承認者としては使われない)。
        $requestId = (string) Str::uuid();
        $approverUserId = $data['approver_user_id'] ?? $request->user()->id;

        // 2つのコマンド発行(CommandBus::dispatchはそれぞれ独自のDBトランザクションで包む)を
        // 外側のトランザクションでまとめ、後段の残数不足等でApprovePaidLeaveRequestが例外を
        // 投げた場合でも、先に作成したPaidLeaveRequest行(submitted状態)が残らないようにする。
        $paidLeaveRequest = DB::transaction(function () use ($commandBus, $data, $requestId, $approverUserId, $request) {
            $commandBus->dispatch(new RequestPaidLeave(
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

            return $commandBus->dispatch(new ApprovePaidLeaveRequestCommand(
                paidLeaveRequestId: $requestId,
                approvedByUserId: null,
            ));
        });

        return (new PaidLeaveRequestResource($paidLeaveRequest->load('user', 'approver')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/paid-leave/requests/mine',
        operationId: 'paidLeave.requests.mine',
        summary: '自分の有給申請一覧を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $requests = PaidLeaveRequest::query()
            ->with('user', 'approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('target_date')
            ->get();

        return PaidLeaveRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/paid-leave/requests/to-approve',
        operationId: 'paidLeave.requests.toApprove',
        summary: '承認待ち有給申請一覧を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function requestsToApprove(Request $request): AnonymousResourceCollection
    {
        $requests = PaidLeaveRequest::query()
            ->with('user', 'approver')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', PaidLeaveRequestStatus::SUBMITTED)
            ->orderBy('target_date')
            ->get();

        return PaidLeaveRequestResource::collection($requests);
    }

    /**
     * UC-P004: 有給を承認する。
     */
    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/approve',
        operationId: 'paidLeave.requests.approve',
        summary: '有給申請を承認する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        // UC-P004: 承認はworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ApproveWorkflowRequestを発行する。
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $paidLeaveRequest,
                '対応する申請が見つからないため承認できません。',
            ),
            approvedByUserId: $request->user()->id,
        ));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/return',
        operationId: 'paidLeave.requests.return',
        summary: '有給申請を差し戻す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        // UC-P004 手順2: 差戻しはworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ReturnWorkflowRequestを発行する。
        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $paidLeaveRequest,
                '対応する申請が見つからないため差し戻せません。',
            ),
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/cancel',
        operationId: 'paidLeave.requests.cancel',
        summary: '有給申請を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new CancelPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 管理者が対象社員の有給申請を取り消す(自分の申請のみ取消可能な`cancelRequest`とは別に、
     * 管理者は他者の承認済み申請も取り消せる。実際に取消操作をしたのは管理者自身のため、
     * cancelledByUserIdは申請者本人ではなく操作者(管理者)のIDを渡す)。
     */
    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/admin-cancel',
        operationId: 'paidLeave.requests.adminCancel',
        summary: '管理者が社員の有給申請を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function adminCancelRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new CancelPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id, isAdminAction: true));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 管理者が対象社員の有給消化明細(paid_leave_usages)を確認する。取消は明細単位では
     * できず、明細に紐づく申請(`paid_leave_request_id`)を`adminCancelRequest`で取り消すことで
     * 反映される。フロント側で取消可否を判定できるよう、関連する申請の現在ステータス
     * (`request_status`)も併せて返す。
     */
    #[OA\Get(
        path: '/paid-leave/usages/user/{userId}',
        operationId: 'paidLeave.usages.forUser',
        summary: '社員の有給消化明細を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function usagesForUser(string $userId): AnonymousResourceCollection
    {
        $usages = PaidLeaveUsage::query()
            ->with('request')
            ->where('user_id', $userId)
            ->orderByDesc('used_on')
            ->get();

        return PaidLeaveUsageResource::collection($usages);
    }

    /**
     * UC-P007: 自分の有給履歴を確認する。EventStore(stored_events)を正の記録として
     * 直接検索する(付与・申請・承認・差戻し・取消・消化・警告のすべてを時系列で表示するため、
     * 現残高スナップショットのみを返す `myGrants` とは別に用意する)。
     */
    #[OA\Get(
        path: '/paid-leave/history/mine',
        operationId: 'paidLeave.history.mine',
        summary: '自分の有給履歴を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myHistory(Request $request): AnonymousResourceCollection
    {
        return $this->historyResponse($request->user()->id);
    }

    /**
     * UC-P007: 管理者・人事担当者が対象社員の有給履歴を確認する。他の管理者向け
     * エンドポイント(`grantsForUser`等)と同様、認可はルート側のFeature・Permission
     * で行う。
     */
    #[OA\Get(
        path: '/paid-leave/history/user/{userId}',
        operationId: 'paidLeave.history.forUser',
        summary: '社員の有給履歴を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function historyForUser(string $userId): AnonymousResourceCollection
    {
        return $this->historyResponse($userId);
    }

    /**
     * 承認・差戻し対象のworkflow_request(subject_type=paid_leave_request)を特定する。
     * 見つからない場合に黙って何もしないと、状態が変わらないまま200を返してしまうため
     * DomainRuleExceptionを投げる。
     */
    private function submittedWorkflowRequestId(PaidLeaveRequest $paidLeaveRequest, string $message): string
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST)
            ->where('subject_id', $paidLeaveRequest->id)
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->latest()
            ->first();

        if ($workflowRequest === null) {
            throw new DomainRuleException($message);
        }

        return $workflowRequest->id;
    }

    /**
     * `paid_leave_grant`/`paid_leave_request` それぞれの集約に属するイベントを時系列で返す
     * (LeaveHistoryQuery参照。有給・特別休暇で共通の読み取り専用Query)。
     */
    private function historyResponse(string $userId): AnonymousResourceCollection
    {
        $events = LeaveHistoryQuery::eventsForUser(
            userId: $userId,
            grantModelClass: PaidLeaveGrant::class,
            requestModelClass: PaidLeaveRequest::class,
        );

        return StoredEventResource::collection($events);
    }
}
