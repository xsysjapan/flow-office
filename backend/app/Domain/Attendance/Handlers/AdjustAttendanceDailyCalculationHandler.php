<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\AdjustAttendanceDailyCalculation;
use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceDay;

/**
 * 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正する。
 * 締め後・承認済み月次に属する日次勤怠は、実績編集と同様に修正申請ワークフローを使う
 * (AttendanceEditGuard参照)。
 *
 * @implements CommandHandler<AdjustAttendanceDailyCalculation>
 */
class AdjustAttendanceDailyCalculationHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof AdjustAttendanceDailyCalculation);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        $this->guard->assertMutable($day, $day->user_id, $day->work_date->toDateString());
        $prescribedHolidayWorkMinutes = (int) ($day->calculation?->prescribed_holiday_work_minutes ?? 0);

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDailyCalculationAdjusted(
                attendanceDayId: $day->id,
                prescribedWorkMinutes: $command->prescribedWorkMinutes,
                statutoryWithinOvertimeMinutes: $command->statutoryWithinOvertimeMinutes,
                statutoryExcessOvertimeMinutes: $command->statutoryExcessOvertimeMinutes,
                legalHolidayWorkMinutes: $command->legalHolidayWorkMinutes,
                prescribedHolidayWorkMinutes: $prescribedHolidayWorkMinutes,
                lateNightPrescribedWorkMinutes: $command->lateNightPrescribedWorkMinutes,
                lateNightStatutoryWithinOvertimeMinutes: $command->lateNightStatutoryWithinOvertimeMinutes,
                lateNightStatutoryExcessOvertimeMinutes: $command->lateNightStatutoryExcessOvertimeMinutes,
                lateNightLegalHolidayWorkMinutes: $command->lateNightLegalHolidayWorkMinutes,
                reason: $command->reason,
                adjustedByUserId: $command->adjustedByUserId,
            ),
        );

        return $day;
    }
}
