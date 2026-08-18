<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate;
use App\Domain\PaidLeave\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveUsage;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 有給申請を取り消す。対象日の勤怠(attendance_days.work_type)は申請時点
 * (RequestPaidLeaveHandler)で既に反映されているため、提出中(未承認)・承認済みの
 * どちらの取消でも巻き戻しが必要(承認済みの場合はさらに消化済みのpaid_leave_grantへの
 * 反映(残数を戻す)も伴う。ApprovePaidLeaveRequestHandlerの反対の操作)。月次勤怠が
 * 既に確定(締め)済みの場合は取消できない(AttendanceEditGuard::assertMutableが
 * 同じ基準で他の編集操作をブロックするのと同様)。
 *
 * @implements CommandHandler<CancelPaidLeaveRequest>
 */
class CancelPaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof CancelPaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if (! $command->isAdminAction && $request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の有給申請のみ取消できます。');
        }

        if (! in_array($request->status, [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED], true)) {
            throw new DomainRuleException('提出済みまたは承認済みの有給申請のみ取消できます。');
        }

        $wasApproved = $request->status === PaidLeaveRequestStatus::APPROVED;

        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->first();

        $this->guard->assertMutable($day, $request->user_id, $request->target_date->toDateString());

        $aggregates = [
            PaidLeaveRequestAggregate::retrieve($request->id)->cancel($command->cancelledByUserId),
        ];

        // 承認済みの取消のみ、消化済み(is_confirmed=true)の各grantへ取消を反映する。
        // 未承認の取消は、まだgrant消化が発生していない(未確定のpaid_leave_usages行が
        // PaidLeaveUsageProjector::onPaidLeaveRequestCancelledで削除されるのみ)。
        if ($wasApproved) {
            $usages = PaidLeaveUsage::query()
                ->where('paid_leave_request_id', $request->id)
                ->where('is_confirmed', true)
                ->get();

            foreach ($usages as $usage) {
                $aggregates[] = PaidLeaveGrantAggregate::retrieve($usage->paid_leave_grant_id)->reverseUsage(
                    userId: $request->user_id,
                    paidLeaveRequestId: $request->id,
                    attendanceDayId: $usage->attendance_day_id,
                    usedOn: $usage->used_on->toDateString(),
                    usedDays: (float) $usage->used_days,
                    usedMinutes: $usage->used_minutes,
                    usageType: $usage->usage_type,
                );
            }
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        if ($day !== null) {
            $day->refresh();
            $day->work_type = null;
            // 全休の場合、申請時に打刻無しでもclocked_out扱いにしていたため
            // (RequestPaidLeaveHandler::reflectOnAttendanceDay)、実際の打刻が無いままなら
            // その状態も巻き戻す。半休・時間休は実働時間があるため打刻由来のステータスを
            // そのまま維持する。
            if ($day->actual_start_at === null && $day->actual_end_at === null) {
                $day->status = AttendanceDayStatus::NOT_STARTED;
            }
            $day->save();

            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
            );

            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        return PaidLeaveRequest::query()->findOrFail($request->id);
    }
}
