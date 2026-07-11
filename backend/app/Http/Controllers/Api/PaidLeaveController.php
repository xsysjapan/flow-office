<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
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
use App\Models\StoredEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * 有給残数管理・申請・承認 (docs/09-usecases-paid-leave.md UC-P001〜UC-P004, docs/21-mvp-scope.md)。
 * 継続勤務期間・出勤率に基づく自動付与バッチ、消滅警告、年5日取得義務警告は後続フェーズで実装する。
 */
class PaidLeaveController extends Controller
{
    public function indexRules(): AnonymousResourceCollection
    {
        return PaidLeaveGrantRuleResource::collection(
            PaidLeaveGrantRule::query()->with('steps')->orderBy('name')->get()
        );
    }

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
    public function myGrants(Request $request): AnonymousResourceCollection
    {
        $grants = PaidLeaveGrant::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('expires_on')
            ->get();

        return PaidLeaveGrantResource::collection($grants);
    }

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

    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $requests = PaidLeaveRequest::query()
            ->with('user', 'approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('target_date')
            ->get();

        return PaidLeaveRequestResource::collection($requests);
    }

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
    public function approveRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new ApprovePaidLeaveRequest($paidLeaveRequest->id, $request->user()->id));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    public function returnRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id, $data['comment']));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

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
    public function myHistory(Request $request): AnonymousResourceCollection
    {
        return $this->historyResponse($request->user()->id);
    }

    /**
     * UC-P007: 管理者・人事担当者が対象社員の有給履歴を確認する。他の管理者向け
     * エンドポイント(`grantsForUser`等)と同様、ロール制限はルート側(`role:admin,hr_staff`)
     * で行う。
     */
    public function historyForUser(int $userId): AnonymousResourceCollection
    {
        return $this->historyResponse($userId);
    }

    /**
     * `paid_leave_grant`/`paid_leave_request` それぞれの集約に属するイベントを時系列で返す。
     * `paid_leave.request_approved`/`request_returned`/`request_cancelled` のpayloadには
     * 申請者本人の `user_id` ではなく実行者(承認者等)のIDしか含まれないため、payloadの中身で
     * 絞り込むのではなく、対象社員が実際に持つ `paid_leave_grants`/`paid_leave_requests` の
     * id(=aggregate_id)で絞り込む。
     */
    private function historyResponse(int $userId): AnonymousResourceCollection
    {
        $grantIds = PaidLeaveGrant::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (string) $id);
        $requestIds = PaidLeaveRequest::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (string) $id);

        $events = StoredEvent::query()
            ->where(function ($query) use ($grantIds, $requestIds) {
                $query->where(fn ($q) => $q->where('aggregate_type', 'paid_leave_grant')->whereIn('aggregate_id', $grantIds))
                    ->orWhere(fn ($q) => $q->where('aggregate_type', 'paid_leave_request')->whereIn('aggregate_id', $requestIds));
            })
            // occurred_atは秒単位までしか保持しないため、同一リクエスト内で複数イベントが
            // 記録された場合に順序が曖昧にならないよう、idを副次的な並び順として使う
            // (idは常に記録順に単調増加するため)。
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return StoredEventResource::collection($events);
    }
}
