<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\EventSourcing\ManualGrantId;
use App\Domain\Leave\Support\LeaveHistoryQuery;
use App\Domain\LeaveRequestLink\LeaveRequestWorkflowLinks;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeaveToUsers;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\SpecialLeave\Commands\RevokeSpecialLeaveGrant;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\ChangeSpecialLeaveGrantAmount;
use App\Domain\UserManagement\Services\GroupMemberExpander;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialLeaveGrantResource;
use App\Http\Resources\SpecialLeaveGrantRuleResource;
use App\Http\Resources\SpecialLeaveRequestResource;
use App\Http\Resources\SpecialLeaveTypeResource;
use App\Http\Resources\SpecialLeaveUsageResource;
use App\Http\Resources\StoredEventResource;
use App\Models\EmployeeCalendarEntry;
use App\Models\LeaveRequestWorkflowLink;
use App\Models\PaidLeaveType;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantRule;
use App\Models\SpecialLeaveGrantStatus;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'requires_grant', type: 'boolean', description: 'falseの場合、事前の付与(残数)が無くても申請できる(忌引・代休等)。省略時はtrue')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'requires_grant' => ['boolean'],
        ]);

        $type = SpecialLeaveType::query()->create(['is_active' => true, 'requires_grant' => true, ...$data]);

        return (new SpecialLeaveTypeResource($type))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/special-leave/types/{specialLeaveType}',
        operationId: 'specialLeave.types.update',
        summary: '特別休暇種別を更新する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveType', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'requires_grant', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function updateType(Request $request, SpecialLeaveType $specialLeaveType): SpecialLeaveTypeResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'requires_grant' => ['boolean'],
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

    /**
     * ある特別休暇付与ルールが現在対象としている社員の一覧(自動付与ON/OFF状態付き)を取得する。
     * PaidLeaveController::targetUsers()と同じ考え方(有給・特別休暇の一括ON/OFFフラグは
     * users.special_leave_auto_grant_enabled、論点1a参照)。
     */
    #[OA\Get(
        path: '/special-leave/grant-rules/{rule}/target-users',
        operationId: 'specialLeave.grantRules.targetUsers',
        summary: '特別休暇付与ルールの対象社員一覧を取得する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function targetUsers(SpecialLeaveGrantRule $rule): JsonResponse
    {
        $query = User::query()->whereNotNull('hire_date');

        if ($rule->work_style_id !== null) {
            $userIds = EmployeeCalendarEntry::query()
                ->where('work_style_id', $rule->work_style_id)
                ->whereDate('work_date', Carbon::now()->toDateString())
                ->pluck('user_id');
            $query->whereIn('id', $userIds);
        }

        $workStyleName = $rule->work_style_id !== null ? $rule->workStyle?->name : null;

        return response()->json([
            'data' => $query->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'work_style' => $workStyleName,
                'special_leave_auto_grant_enabled' => $user->special_leave_auto_grant_enabled,
            ]),
        ]);
    }

    #[OA\Get(
        path: '/special-leave/grants/mine',
        operationId: 'specialLeave.grants.mine',
        summary: '自分の特別休暇残数を取得する(取り消した付与・残りの無い付与は含めない)',
        description: '本人の有効な(status=active)で残日数のある特別休暇付与を返す。管理者により取り消された付与(status=revoked)は含めない。',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myGrants(Request $request): AnonymousResourceCollection
    {
        $grants = SpecialLeaveGrant::query()
            ->with('specialLeaveType')
            ->where('user_id', $request->user()->id)
            ->where('status', SpecialLeaveGrantStatus::ACTIVE)
            ->where('remaining_days', '>', 0)
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'special_leave_type_id', 'granted_on', 'granted_days'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'granted_on', type: 'string', format: 'date'), new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'grant_reason', type: 'string', nullable: true), new OA\Property(property: 'request_id', type: 'string', format: 'uuid', nullable: true, description: '再送を二重付与にしないための識別子(付与IDを request_id と user_id から決める)')])),
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
            'request_id' => ['nullable', 'uuid'],
        ]);

        // request_idがあれば付与IDを request_id と利用者IDから決め、同じ request_id の再送は何もせず成功にする。
        $requestId = $data['request_id'] ?? null;

        $grant = $commandBus->dispatch(new GrantSpecialLeave(
            userId: $data['user_id'],
            specialLeaveTypeId: $data['special_leave_type_id'],
            grantedOn: $data['granted_on'],
            expiresOn: $data['expires_on'] ?? null,
            grantedDays: (float) $data['granted_days'],
            grantReason: $data['grant_reason'] ?? null,
            grantId: $requestId !== null ? ManualGrantId::for('special_leave', $requestId, $data['user_id']) : null,
            idempotent: $requestId !== null,
        ));

        return (new SpecialLeaveGrantResource($grant->load('specialLeaveType')))->response()->setStatusCode(201);
    }

    /**
     * 特別休暇を複数の利用者へ手動付与する(1つのCommand。全部か無しか: 1人でも付与できなければ何も記録せず、
     * 422(code: bulk_operation_rejected)で失敗した利用者と理由を返す)。対象は`user_ids`か`group_id`(実行した時点の
     * 直接の所属のメンバー。退職者を除く。`group.view`を要する)。同じ`request_id`の再送は二重にならず成功する。
     */
    #[OA\Post(
        path: '/special-leave/grants/bulk',
        operationId: 'specialLeave.grants.bulkStore',
        summary: '特別休暇を複数の利用者へ手動付与する(全部か無しか)',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['request_id', 'special_leave_type_id', 'granted_on', 'granted_days'], properties: [new OA\Property(property: 'request_id', type: 'string', format: 'uuid'), new OA\Property(property: 'user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'group_id', type: 'string', format: 'uuid'), new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'granted_on', type: 'string', format: 'date'), new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'grant_reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response(全員に付与)'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error/付与できない利用者がいる(何も記録しない。code: bulk_operation_rejected)')],
    )]
    public function bulkGrant(Request $request, CommandBus $commandBus, GroupMemberExpander $groupMembers): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'user_ids' => ['required_without:group_id', 'prohibits:group_id', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['string', 'distinct', 'exists:users,id'],
            'group_id' => ['required_without:user_ids', 'string', 'exists:groups,id'],
            'special_leave_type_id' => ['required', 'integer', 'exists:special_leave_types,id'],
            'granted_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date', 'after:granted_on'],
            'granted_days' => ['required', 'numeric', 'min:0.5'],
            'grant_reason' => ['nullable', 'string'],
        ]);

        $labels = $groupMembers->resolveTargets($request->user(), $data['group_id'] ?? null, $data['user_ids'] ?? null, 500);

        $commandBus->dispatch(new GrantSpecialLeaveToUsers(
            requestId: $data['request_id'],
            userIds: array_keys($labels),
            specialLeaveTypeId: $data['special_leave_type_id'],
            grantedOn: $data['granted_on'],
            expiresOn: $data['expires_on'] ?? null,
            grantedDays: (float) $data['granted_days'],
            grantReason: $data['grant_reason'] ?? null,
            userLabels: $labels,
        ));

        return response()->json([
            'data' => array_map(fn (string $userId) => ['user_id' => $userId, 'user_name' => $labels[$userId]], array_keys($labels)),
        ]);
    }

    /**
     * 管理者が特別休暇付与の付与日数を変更する。取消済み・使用済みの日数を下回る日数は口座集約が拒否する(422)。
     * 日数が同じならイベントを記録しない。
     */
    #[OA\Put(
        path: '/special-leave/grants/{grant}/granted-days',
        operationId: 'specialLeave.grants.changeGrantedDays',
        summary: '特別休暇付与の付与日数を変更する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'grant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['granted_days'], properties: [new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function changeGrantedDays(Request $request, SpecialLeaveGrant $grant, CommandBus $commandBus): SpecialLeaveGrantResource
    {
        $data = $request->validate([
            'granted_days' => ['required', 'numeric', 'min:0.5'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $commandBus->dispatch(new ChangeSpecialLeaveGrantAmount(
            userId: (string) $grant->user_id,
            grantId: (string) $grant->id,
            newGrantedDays: (float) $data['granted_days'],
            changedByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        return new SpecialLeaveGrantResource($grant->refresh()->load('specialLeaveType'));
    }

    /**
     * 管理者が発行済みの特別休暇付与を取り消す。既に消化された分がある場合は
     * RevokeSpecialLeaveGrantHandlerがDomainRuleExceptionを投げる(422)。
     */
    #[OA\Post(
        path: '/special-leave/grants/{grant}/revoke',
        operationId: 'specialLeave.grants.revoke',
        summary: '特別休暇付与を取り消す',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'grant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function revoke(Request $request, SpecialLeaveGrant $grant, CommandBus $commandBus): SpecialLeaveGrantResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $grant = $commandBus->dispatch(new RevokeSpecialLeaveGrant(
            grantId: $grant->id,
            revokedByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        return new SpecialLeaveGrantResource($grant->load('specialLeaveType'));
    }

    /**
     * 特別休暇を申請する(承認不要の申請)。`RequestSpecialLeave`を1つdispatchするだけで、承認の要否は読まない。
     * 承認が必要な設定ではHandlerが422で拒否する(承認が必要な申請は POST /special-leave/requests/workflow)。
     */
    #[OA\Post(
        path: '/special-leave/requests',
        operationId: 'specialLeave.requests.store',
        summary: '特別休暇を申請する(承認不要の設定)',
        description: '承認不要の設定(公開設定のspecial_leave_requires_approval=false)のときに使う。承認が必要な設定では422(承認が必要です)になるため、POST /special-leave/requests/workflow を使う。',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['special_leave_type_id', 'target_date', 'leave_type'], properties: [new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'reason', type: 'string', nullable: true), new OA\Property(property: 'request_group_id', type: 'string', format: 'uuid', nullable: true, description: '期間指定でまとめて申請した複数日分を束ねるID(単日申請では省略)')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus, LeaveRequestWorkflowLinks $links): JsonResponse
    {
        $data = $this->validateStoreRequest($request, approverRequired: false);

        $specialLeaveRequest = $commandBus->dispatch(new RequestSpecialLeave(
            userId: $request->user()->id,
            specialLeaveTypeId: $data['special_leave_type_id'],
            targetDate: $data['target_date'],
            leaveType: $data['leave_type'],
            hours: $data['hours'] ?? null,
            approverUserId: $data['approver_user_id'] ?? null,
            reason: $data['reason'] ?? null,
            workflowRequestId: null,
            requestId: (string) Str::uuid(),
            requestGroupId: $data['request_group_id'] ?? null,
        ));

        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, [$specialLeaveRequest]);

        return (new SpecialLeaveRequestResource($specialLeaveRequest->load('user', 'approver', 'specialLeaveType')))->response()->setStatusCode(201);
    }

    /**
     * 特別休暇を申請する(承認が必要な申請)。`DraftWorkflowRequest`を1つdispatchするだけで、承認の要否は読まない。
     * 承認不要の設定ではワークフローの下書きのHandlerが422で拒否する(POST /special-leave/requests を使う)。
     */
    #[OA\Post(
        path: '/special-leave/requests/workflow',
        operationId: 'specialLeave.requests.storeWorkflow',
        summary: '特別休暇を申請する(承認が必要な設定。承認者を指定してワークフローを作る)',
        description: '承認が必要な設定(公開設定のspecial_leave_requires_approval=true)のときに使う。承認不要の設定では422(承認が不要です)になるため、POST /special-leave/requests を使う。',
        tags: ['特別休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['special_leave_type_id', 'target_date', 'leave_type', 'approver_user_id'], properties: [new OA\Property(property: 'special_leave_type_id', type: 'integer'), new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'reason', type: 'string', nullable: true), new OA\Property(property: 'request_group_id', type: 'string', format: 'uuid', nullable: true, description: '期間指定でまとめて申請した複数日分を束ねるID(単日申請では省略)')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeWorkflowRequest(Request $request, CommandBus $commandBus, LeaveRequestWorkflowLinks $links): JsonResponse
    {
        $data = $this->validateStoreRequest($request, approverRequired: true);

        // UC-P003: 特別休暇申請はworkflow_requestの下書き作成を起点にする。SpecialLeaveRequest集約への
        // RequestSpecialLeaveはSpecialLeaveRequestOnWorkflowRequestReactorが発行する
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
                'request_group_id' => $data['request_group_id'] ?? null,
            ],
            approverUserId: $data['approver_user_id'],
            subjectType: WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST,
            subjectId: $requestId,
        ));

        $specialLeaveRequest = SpecialLeaveRequest::query()->findOrFail($requestId);
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, [$specialLeaveRequest]);

        return (new SpecialLeaveRequestResource($specialLeaveRequest->load('user', 'approver', 'specialLeaveType')))->response()->setStatusCode(201);
    }

    /**
     * 特別休暇申請の入力の検証(形式のみ)。承認者の指定は、承認が必要な入口でだけ必須にする(承認の要否は読まない)。
     *
     * @return array<string, mixed>
     */
    private function validateStoreRequest(Request $request, bool $approverRequired): array
    {
        return $request->validate([
            'special_leave_type_id' => ['required', 'integer', 'exists:special_leave_types,id'],
            'target_date' => ['required', 'date'],
            'leave_type' => ['required', Rule::in(PaidLeaveType::values())],
            'hours' => ['nullable', 'numeric', 'min:0.5'],
            'approver_user_id' => [$approverRequired ? 'required' : 'nullable', 'string', 'exists:users,id'],
            'reason' => ['nullable', 'string'],
            // 期間指定でまとめて申請した複数日分(1日1リクエスト)を束ねるID。frontend側が
            // 同一の申請操作内で生成した同じ値を全日分に渡す(単日申請では省略)。
            'request_group_id' => ['nullable', 'string', 'uuid'],
        ]);
    }

    #[OA\Get(
        path: '/special-leave/requests/mine',
        operationId: 'specialLeave.requests.mine',
        summary: '自分の特別休暇申請一覧を取得する(対象日の降順・20件ずつ)',
        description: '本人の特別休暇申請を対象日(target_date)の降順、同じ日はidの降順で20件ずつ返す。応答は{ data, links, meta }のページング形式。target_dateを指定するとその日の申請だけを返す。',
        tags: ['特別休暇'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'ページ番号(1以上、既定1)', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'target_date', in: 'query', required: false, description: '対象日(Y-m-d)。指定するとその日の申請だけを返す', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myRequests(Request $request, LeaveRequestWorkflowLinks $links): AnonymousResourceCollection
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'target_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $requests = SpecialLeaveRequest::query()
            ->with('user', 'approver', 'specialLeaveType')
            ->where('user_id', $request->user()->id)
            ->when(isset($data['target_date']), fn ($query) => $query->whereDate('target_date', $data['target_date']))
            ->orderByDesc('target_date')
            ->orderByDesc('id')
            ->paginate(20);
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, $requests);

        return SpecialLeaveRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/special-leave/requests/to-approve',
        operationId: 'specialLeave.requests.toApprove',
        summary: '承認待ち特別休暇申請一覧を取得する',
        tags: ['特別休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function requestsToApprove(Request $request, LeaveRequestWorkflowLinks $links): AnonymousResourceCollection
    {
        $requests = SpecialLeaveRequest::query()
            ->with('user', 'approver', 'specialLeaveType')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', SpecialLeaveRequestStatus::SUBMITTED)
            ->orderBy('target_date')
            ->get();
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, $requests);

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
    public function approveRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus, LeaveRequestWorkflowLinks $links): SpecialLeaveRequestResource
    {
        // UC-P004: 承認はworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ApproveWorkflowRequestを発行する。
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdFor(
                $links,
                $specialLeaveRequest,
                '対応する申請が見つからないため承認できません。',
            ),
            approvedByUserId: $request->user()->id,
        ));

        $specialLeaveRequest = $specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType');
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, [$specialLeaveRequest]);

        return new SpecialLeaveRequestResource($specialLeaveRequest);
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
    public function returnRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus, LeaveRequestWorkflowLinks $links): SpecialLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        // UC-P004 手順2: 差戻しはworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ReturnWorkflowRequestを発行する。
        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->workflowRequestIdFor(
                $links,
                $specialLeaveRequest,
                '対応する申請が見つからないため差し戻せません。',
            ),
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        $specialLeaveRequest = $specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType');
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, [$specialLeaveRequest]);

        return new SpecialLeaveRequestResource($specialLeaveRequest);
    }

    #[OA\Post(
        path: '/special-leave/requests/{specialLeaveRequest}/cancel',
        operationId: 'specialLeave.requests.cancel',
        summary: '特別休暇申請を取り消す',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus, LeaveRequestWorkflowLinks $links): SpecialLeaveRequestResource
    {
        $commandBus->dispatch(new CancelSpecialLeaveRequest($specialLeaveRequest->id, $request->user()->id));

        $specialLeaveRequest = $specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType');
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, [$specialLeaveRequest]);

        return new SpecialLeaveRequestResource($specialLeaveRequest);
    }

    /**
     * 管理者が対象社員の特別休暇申請を取り消す(自分の申請のみ取消可能な`cancelRequest`とは
     * 別に、管理者は他者の承認済み申請も取り消せる。cancelledByUserIdは申請者本人ではなく
     * 操作者(管理者)のIDを渡す)。
     */
    #[OA\Post(
        path: '/special-leave/requests/{specialLeaveRequest}/admin-cancel',
        operationId: 'specialLeave.requests.adminCancel',
        summary: '管理者が社員の特別休暇申請を取り消す',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'specialLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function adminCancelRequest(Request $request, SpecialLeaveRequest $specialLeaveRequest, CommandBus $commandBus, LeaveRequestWorkflowLinks $links): SpecialLeaveRequestResource
    {
        $commandBus->dispatch(new CancelSpecialLeaveRequest($specialLeaveRequest->id, $request->user()->id, isAdminAction: true));

        $specialLeaveRequest = $specialLeaveRequest->refresh()->load('user', 'approver', 'specialLeaveType');
        $links->attachWorkflowRequestIds(LeaveRequestWorkflowLink::KIND_SPECIAL, [$specialLeaveRequest]);

        return new SpecialLeaveRequestResource($specialLeaveRequest);
    }

    /**
     * 管理者が対象社員の特別休暇消化明細(special_leave_usages)を確認する。取消は明細単位
     * ではできず、明細に紐づく申請を`adminCancelRequest`で取り消すことで反映される。
     */
    #[OA\Get(
        path: '/special-leave/usages/user/{userId}',
        operationId: 'specialLeave.usages.forUser',
        summary: '社員の特別休暇消化明細を取得する',
        tags: ['特別休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function usagesForUser(string $userId): AnonymousResourceCollection
    {
        $usages = SpecialLeaveUsage::query()
            ->with('request')
            ->where('user_id', $userId)
            ->orderByDesc('used_on')
            ->get();

        return SpecialLeaveUsageResource::collection($usages);
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
            // 付与・消化記録は利用者単位の特別休暇口座の集約のイベント(集約IDは利用者から派生)に記録される。
            additionalAggregateIds: [SpecialLeaveAccountAggregate::streamIdFor($userId)],
        );

        return StoredEventResource::collection($events);
    }

    /**
     * 承認・差戻し対象のワークフローIDを対応表(leave_request_workflow_links)から特定する。
     * workflow_requestsは読まない(原則15。休暇申請文脈の対応表だけで申請を特定する)。
     * 見つからない場合に黙って何もしないと、状態が変わらないまま200を返してしまうため
     * DomainRuleExceptionを投げる。
     */
    private function workflowRequestIdFor(LeaveRequestWorkflowLinks $links, SpecialLeaveRequest $specialLeaveRequest, string $message): string
    {
        $workflowRequestId = $links->workflowRequestIdFor(LeaveRequestWorkflowLink::KIND_SPECIAL, (string) $specialLeaveRequest->id);

        if ($workflowRequestId === null) {
            throw new DomainRuleException($message);
        }

        return $workflowRequestId;
    }
}
