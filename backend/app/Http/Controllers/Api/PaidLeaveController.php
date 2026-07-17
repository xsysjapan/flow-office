<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Leave\Support\LeaveHistoryQuery;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\GrantPaidLeave;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaidLeaveGrantResource;
use App\Http\Resources\PaidLeaveGrantRuleResource;
use App\Http\Resources\PaidLeaveRequestResource;
use App\Http\Resources\StoredEventResource;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'work_style_id', type: 'integer', nullable: true), new OA\Property(property: 'min_attendance_rate', type: 'integer'), new OA\Property(property: 'first_grant_after_months', type: 'integer'), new OA\Property(property: 'grant_cycle_months', type: 'integer'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'work_style_id' => ['nullable', 'integer', 'exists:work_styles,id'],
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
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function grantsForUser(int $userId): AnonymousResourceCollection
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'granted_on', 'expires_on', 'granted_days'], properties: [new OA\Property(property: 'user_id', type: 'integer'), new OA\Property(property: 'granted_on', type: 'string', format: 'date'), new OA\Property(property: 'expires_on', type: 'string', format: 'date'), new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'grant_reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function grant(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
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
     * UC-P003: 有給を申請する。
     */
    #[OA\Post(
        path: '/paid-leave/requests',
        operationId: 'paidLeave.requests.store',
        summary: '有給を申請する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_date', 'leave_type', 'approver_user_id'], properties: [new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'integer'), new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'target_date' => ['required', 'date'],
            'leave_type' => ['required', Rule::in(PaidLeaveType::values())],
            'hours' => ['nullable', 'numeric', 'min:0.5'],
            'approver_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string'],
        ]);

        $paidLeaveRequest = $commandBus->dispatch(new RequestPaidLeave(
            userId: $request->user()->id,
            targetDate: $data['target_date'],
            leaveType: $data['leave_type'],
            hours: isset($data['hours']) ? (float) $data['hours'] : null,
            approverUserId: $data['approver_user_id'],
            reason: $data['reason'] ?? null,
        ));

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
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new ApprovePaidLeaveRequest($paidLeaveRequest->id, $request->user()->id));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/return',
        operationId: 'paidLeave.requests.return',
        summary: '有給申請を差し戻す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id, $data['comment']));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/cancel',
        operationId: 'paidLeave.requests.cancel',
        summary: '有給申請を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new CancelPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
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
     * エンドポイント(`grantsForUser`等)と同様、ロール制限はルート側(`role:admin,hr_staff`)
     * で行う。
     */
    #[OA\Get(
        path: '/paid-leave/history/user/{userId}',
        operationId: 'paidLeave.history.forUser',
        summary: '社員の有給履歴を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function historyForUser(int $userId): AnonymousResourceCollection
    {
        return $this->historyResponse($userId);
    }

    /**
     * `paid_leave_grant`/`paid_leave_request` それぞれの集約に属するイベントを時系列で返す
     * (LeaveHistoryQuery参照。有給・特別休暇で共通の読み取り専用Query)。
     */
    private function historyResponse(int $userId): AnonymousResourceCollection
    {
        $events = LeaveHistoryQuery::eventsForUser(
            userId: $userId,
            grantModelClass: PaidLeaveGrant::class,
            grantAggregateType: 'paid_leave_grant',
            requestModelClass: PaidLeaveRequest::class,
            requestAggregateType: 'paid_leave_request',
        );

        return StoredEventResource::collection($events);
    }
}
