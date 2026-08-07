<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\CompensatoryLeaveRequest;
use App\Models\CompensatoryLeaveRequestStatus;
use App\Models\CompensatoryLeaveUsage;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 代休消化申請を取り消す。対象日の勤怠(attendance_days.work_type)は申請時点
 * (RequestCompensatoryLeaveHandler)で既に反映されているため、提出中(未承認)・承認済みの
 * どちらの取消でも巻き戻しが必要(承認済みの場合はさらに消化済みのcompensatory_leave_grantへの
 * 反映(残数を戻す)も伴う。ApproveCompensatoryLeaveRequestHandlerの反対の操作。
 * CancelPaidLeaveRequestHandlerと同じ考え方)。月次勤怠が既に確定(締め)済みの場合は
 * 取消できない(AttendanceEditGuard::assertMutableが同じ基準で他の編集操作をブロックする
 * のと同様)。
 *
 * @implements CommandHandler<CancelCompensatoryLeaveRequest>
 */
class CancelCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): CompensatoryLeaveRequest
    {
        assert($command instanceof CancelCompensatoryLeaveRequest);

        $request = CompensatoryLeaveRequest::query()->findOrFail($command->compensatoryLeaveRequestId);

        if ($request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の代休申請のみ取消できます。');
        }

        if (! in_array($request->status, [CompensatoryLeaveRequestStatus::SUBMITTED, CompensatoryLeaveRequestStatus::APPROVED], true)) {
            throw new DomainRuleException('提出済みまたは承認済みの代休申請のみ取消できます。');
        }

        $wasApproved = $request->status === CompensatoryLeaveRequestStatus::APPROVED;

        // 対象日の勤怠(attendance_days.work_type)は申請時点(RequestCompensatoryLeaveHandler)で
        // 既に反映されているため、提出中(未承認)・承認済みのどちらの取消でも巻き戻しが必要
        // (CancelPaidLeaveRequestHandlerと同じ考え方)。
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->first();

        $this->guard->assertMutable($day, $request->user_id, $request->target_date->toDateString());

        $aggregates = [
            CompensatoryLeaveRequestAggregate::retrieve($request->id)->cancel($command->cancelledByUserId),
        ];

        // 承認済みの取消のみ、消化済み(is_confirmed=true)の各grantへ取消を反映する。
        // 未承認の取消は、まだgrant消化が発生していない(未確定のcompensatory_leave_usages行が
        // CompensatoryLeaveGrantProjector::onCompensatoryLeaveRequestCancelledで削除されるのみ)。
        if ($wasApproved) {
            $usages = CompensatoryLeaveUsage::query()
                ->where('compensatory_leave_request_id', $request->id)
                ->where('is_confirmed', true)
                ->get();

            foreach ($usages as $usage) {
                $aggregates[] = CompensatoryLeaveGrantAggregate::retrieve($usage->compensatory_leave_grant_id)->reverseUsage(
                    userId: $request->user_id,
                    compensatoryLeaveRequestId: $request->id,
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
            // (RequestCompensatoryLeaveHandler::reflectOnAttendanceDay)、実際の打刻が無いままなら
            // その状態も巻き戻す。半休・時間休は実働時間があるため打刻由来のステータスを
            // そのまま維持する。
            if ($day->actual_start_at === null && $day->actual_end_at === null) {
                $day->status = AttendanceDayStatus::NOT_STARTED;
            }
            $day->save();

            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'),
            );

            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        return CompensatoryLeaveRequest::query()->findOrFail($request->id);
    }
}
