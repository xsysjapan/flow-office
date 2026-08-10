<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\PaidLeaveType;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 代休消化申請を承認する。対象日の勤怠(attendance_days.work_type)への反映は
 * 申請時点(RequestCompensatoryLeaveHandler)で既に行われているため、承認時に行うのは
 * 失効日が近い確定済みGrantから優先的に消し込む消化の確定と、それに伴う日次集計の
 * 再計算のみ。残数が不足していても(マイナスになっても)承認自体は成立させる
 * (RequestCompensatoryLeaveHandler冒頭のコメント参照。残数は承認済み分のみで計測する
 * 方針のため、ここで消化できなかった分は単に記録しない)。
 *
 * 承認1件で「compensatory_leave_request集約の承認」と「1件以上の
 * compensatory_leave_grant集約の消化」にまたがるため、
 * `AggregateRoot::persistInTransaction()`で1トランザクションにまとめて記録する
 * (ApprovePaidLeaveRequestHandlerと同じ考え方)。
 *
 * @implements CommandHandler<ApproveCompensatoryLeaveRequest>
 */
class ApproveCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof ApproveCompensatoryLeaveRequest);

        $request = CompensatoryLeaveRequest::query()->findOrFail($command->compensatoryLeaveRequestId);

        if ($request->status !== CompensatoryLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの代休申請のみ承認できます。');
        }

        // approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による承認不要の即時確定
        // (CompensatoryLeaveController::storeRequest参照)であり、承認者チェックそのものを行わない。
        if ($command->approvedByUserId !== null && $request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // 申請時点(RequestCompensatoryLeaveHandler)で対象日の勤怠は必ず作成済みのため、
        // ここでは参照するのみ(存在しない場合は不整合として例外にする)。
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->firstOrFail();

        $isHourly = $request->leave_type === PaidLeaveType::HOURLY;
        $plan = $this->planConsumption($request, $isHourly);

        $aggregates = [
            CompensatoryLeaveRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
        ];

        foreach ($plan as ['grant' => $grant, 'amount' => $amount]) {
            $aggregates[] = CompensatoryLeaveGrantAggregate::retrieve($grant->id)->use(
                userId: $request->user_id,
                compensatoryLeaveRequestId: $request->id,
                attendanceDayId: $day->id,
                usedOn: $request->target_date->toDateString(),
                usedDays: $isHourly ? 0.0 : $amount,
                usedMinutes: $isHourly ? (int) round($amount) : null,
                usageType: $request->leave_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        $request = CompensatoryLeaveRequest::query()->findOrFail($request->id);

        if ($plan !== []) {
            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'),
            );
            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        return $request;
    }

    /**
     * 消化計画を確定する。残数不足でも承認自体はブロックせず、消化できる分だけを
     * 記録する(残数が0のgrantへ紐付けることはできないため、不足分は単に記録しない)。
     *
     * @return array<int, array{grant: CompensatoryLeaveGrant, amount: float}>
     */
    private function planConsumption(CompensatoryLeaveRequest $request, bool $isHourly): array
    {
        $column = $isHourly ? 'remaining_minutes' : 'remaining_days';
        $remainingToConsume = $isHourly ? (float) $request->requested_minutes : (float) $request->requested_days;
        $plan = [];

        $grants = CompensatoryLeaveGrant::query()
            ->availableOn($request->target_date->toDateString())
            ->where('user_id', $request->user_id)
            ->where('status', CompensatoryLeaveGrantStatus::CONFIRMED)
            ->where($column, '>', 0)
            // 失効日が近い付与分から優先的に消し込む。無期限(expires_on=null)の付与は最後に消化する。
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        foreach ($grants as $grant) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $grant->{$column}, $remainingToConsume);
            $plan[] = ['grant' => $grant, 'amount' => $consume];
            $remainingToConsume -= $consume;
        }

        return $plan;
    }
}
