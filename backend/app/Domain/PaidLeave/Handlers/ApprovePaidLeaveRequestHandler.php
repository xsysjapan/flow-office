<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Models\AttendanceDay;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * UC-P004: 有給を承認する。対象日の勤怠(attendance_days.work_type)への反映は
 * 申請時点(RequestPaidLeaveHandler)で既に行われているため、承認時に行うのは
 * 有効期限が近い付与分からの消化(grant消費の確定)と、それに伴う日次集計
 * (paid_leave_minutes/paid_leave_days)の再計算のみ。残数が不足していても
 * (マイナスになっても)承認自体は成立させる(RequestPaidLeaveHandler冒頭のコメント参照。
 * 残数は承認済み分のみで計測する方針のため、ここで消化できなかった分は単に記録しない)。
 *
 * paid_leave.usedイベントは、申請時点でPaidLeaveUsageProjectorが作成した未確定
 * (grant_id未設定・is_confirmed=false)のpaid_leave_usages行を、最初の1件はその場で
 * 確定済み(grant_id設定・is_confirmed=true)へ更新し、複数grantにまたがる場合は2件目以降を
 * 新規の確定済み行として追加する(同Projector参照)。
 *
 * 承認1件で「paid_leave_request集約の承認」と「1件以上のpaid_leave_grant集約の消化」に
 * またがるため、`AggregateRoot::persistInTransaction()`で1トランザクションにまとめて記録する
 * (DeviceAdminSessionOpenerに次ぐ2例目の複数集約トランザクション。
 * docs/29-event-sourcing-framework-migration.md参照)。
 *
 * @implements CommandHandler<ApprovePaidLeaveRequest>
 */
class ApprovePaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ApprovePaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if ($request->status !== PaidLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの有給申請のみ承認できます。');
        }

        // approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による承認不要の即時確定
        // (PaidLeaveController::storeRequest参照)であり、承認者チェックそのものを行わない。
        if ($command->approvedByUserId !== null && $request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // 申請時点(RequestPaidLeaveHandler)で対象日の勤怠は必ず作成済みのため、
        // ここでは参照するのみ(存在しない場合は不整合として例外にする)。
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->firstOrFail();

        $plan = $this->planConsumption($request);

        $usedMinutes = $request->hours !== null ? (int) round($request->hours * 60) : null;

        $aggregates = [
            PaidLeaveRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
        ];

        foreach ($plan as ['grant' => $grant, 'amount' => $amount]) {
            $aggregates[] = PaidLeaveGrantAggregate::retrieve($grant->id)->use(
                userId: $request->user_id,
                paidLeaveRequestId: $request->id,
                attendanceDayId: $day->id,
                usedOn: $request->target_date->toDateString(),
                usedDays: $amount,
                usedMinutes: $usedMinutes,
                usageType: $request->leave_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        if ($plan !== []) {
            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'),
            );
            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        return PaidLeaveRequest::query()->findOrFail($request->id);
    }

    /**
     * 消化計画を確定する。残数不足でも承認自体はブロックせず、消化できる分だけを
     * 記録する(残数が0のgrantへ紐付けることはできないため、不足分は単に記録しない)。
     *
     * @return array<int, array{grant: PaidLeaveGrant, amount: float}>
     */
    private function planConsumption(PaidLeaveRequest $request): array
    {
        $remainingToConsume = (float) $request->requested_days;
        $plan = [];

        $grants = PaidLeaveGrant::query()
            ->where('user_id', $request->user_id)
            ->whereDate('expires_on', '>=', $request->target_date)
            ->where('remaining_days', '>', 0)
            ->orderBy('expires_on')
            ->get();

        foreach ($grants as $grant) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $grant->remaining_days, $remainingToConsume);
            $plan[] = ['grant' => $grant, 'amount' => $consume];
            $remainingToConsume -= $consume;
        }

        return $plan;
    }
}
