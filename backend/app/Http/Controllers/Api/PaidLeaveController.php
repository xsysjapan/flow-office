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
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
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
}
