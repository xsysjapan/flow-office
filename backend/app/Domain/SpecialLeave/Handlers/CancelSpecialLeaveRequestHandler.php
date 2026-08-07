<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveGrantAggregate;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use App\Models\SpecialLeaveUsage;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 特別休暇申請を取り消す。提出中(未承認)の取消は申請の取消のみで副作用が無いが、
 * 承認済みの取消は消化済みのspecial_leave_grantへの反映(残数を戻す)と、対象日の
 * 勤怠(attendance_days.work_type)の巻き戻しを伴う(ApproveSpecialLeaveRequestHandlerの
 * 反対の操作)。月次勤怠が既に確定(締め)済みの場合は取消できない
 * (AttendanceEditGuard::assertMutableが同じ基準で他の編集操作をブロックするのと同様)。
 *
 * @implements CommandHandler<CancelSpecialLeaveRequest>
 */
class CancelSpecialLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof CancelSpecialLeaveRequest);

        $request = SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);

        if ($request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の特別休暇申請のみ取消できます。');
        }

        if (! in_array($request->status, [SpecialLeaveRequestStatus::SUBMITTED, SpecialLeaveRequestStatus::APPROVED], true)) {
            throw new DomainRuleException('提出済みまたは承認済みの特別休暇申請のみ取消できます。');
        }

        if ($request->status === SpecialLeaveRequestStatus::SUBMITTED) {
            SpecialLeaveRequestAggregate::retrieve($request->id)->cancel($command->cancelledByUserId)->persist();

            return $request->refresh();
        }

        // 承認済みの取消: 消化済みの各grantへ取消を反映し、対象日の勤怠を巻き戻す。
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->first();

        $this->guard->assertMutable($day, $request->user_id, $request->target_date->toDateString());

        $usages = SpecialLeaveUsage::query()->where('special_leave_request_id', $request->id)->get();

        $aggregates = [
            SpecialLeaveRequestAggregate::retrieve($request->id)->cancel($command->cancelledByUserId),
        ];

        foreach ($usages as $usage) {
            $aggregates[] = SpecialLeaveGrantAggregate::retrieve($usage->special_leave_grant_id)->reverseUsage(
                userId: $request->user_id,
                specialLeaveRequestId: $request->id,
                attendanceDayId: $usage->attendance_day_id,
                usedOn: $usage->used_on->toDateString(),
                usedDays: (float) $usage->used_days,
                usedMinutes: $usage->used_minutes,
                usageType: $usage->usage_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        if ($day !== null) {
            $day->refresh();
            $day->work_type = null;
            // 全休の場合、承認時に打刻無しでもclocked_out扱いにしていたため
            // (ApproveSpecialLeaveRequestHandler::reflectOnAttendanceDay)、実際の打刻が
            // 無いままならその状態も巻き戻す。半休・時間休は実働時間があるため打刻由来の
            // ステータスをそのまま維持する。
            if ($day->actual_start_at === null && $day->actual_end_at === null) {
                $day->status = AttendanceDayStatus::NOT_STARTED;
            }
            $day->save();

            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'),
            );

            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        return SpecialLeaveRequest::query()->findOrFail($request->id);
    }
}
