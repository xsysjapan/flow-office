<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\AdjustAttendanceDailyCalculation;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
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
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof AdjustAttendanceDailyCalculation);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        $this->guard->assertMutable($day, $day->user_id, $day->work_date->toDateString());
        // payroll_work_minutes(給与計算上の労働時間。裁量労働制のみなし時間はここに反映される)を
        // 指定しなかった場合は現在値を維持する(他の区分と異なり、みなし時間が関係しない
        // 通常の勤務形態では補正不要なため必須にしていない)。
        $payrollWorkMinutes = $command->payrollWorkMinutes ?? (int) ($day->calculation?->payroll_work_minutes ?? 0);

        AttendanceDayAggregate::retrieve($day->id)
            ->adjustCalculation(
                prescribedWorkMinutes: $command->prescribedWorkMinutes,
                statutoryWithinOvertimeMinutes: $command->statutoryWithinOvertimeMinutes,
                statutoryExcessOvertimeMinutes: $command->statutoryExcessOvertimeMinutes,
                legalHolidayWorkMinutes: $command->legalHolidayWorkMinutes,
                prescribedHolidayWorkMinutes: $command->prescribedHolidayWorkMinutes,
                payrollWorkMinutes: $payrollWorkMinutes,
                lateNightPrescribedWorkMinutes: $command->lateNightPrescribedWorkMinutes,
                lateNightStatutoryWithinOvertimeMinutes: $command->lateNightStatutoryWithinOvertimeMinutes,
                lateNightStatutoryExcessOvertimeMinutes: $command->lateNightStatutoryExcessOvertimeMinutes,
                lateNightLegalHolidayWorkMinutes: $command->lateNightLegalHolidayWorkMinutes,
                lateNightPrescribedHolidayWorkMinutes: $command->lateNightPrescribedHolidayWorkMinutes,
                reason: $command->reason,
                adjustedByUserId: $command->adjustedByUserId,
            )
            ->persist();

        return $day;
    }
}
