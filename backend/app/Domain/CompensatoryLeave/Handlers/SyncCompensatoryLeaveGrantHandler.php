<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Commands\SyncCompensatoryLeaveGrant;
use App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\AttendanceDay;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\DayClassification;
use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * 休日出勤の勤怠実績(attendance_days)から代休Grant(draft)を自動同期する
 * (SyncCompensatoryLeaveGrantOnAttendanceDayCalculatedReactorから発行される)。
 *
 * @implements CommandHandler<SyncCompensatoryLeaveGrant>
 */
class SyncCompensatoryLeaveGrantHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof SyncCompensatoryLeaveGrant);

        $settings = SystemSetting::current();
        if (! $settings->compensatory_leave_enabled) {
            return null;
        }

        $day = AttendanceDay::query()->with('calculation')->find($command->attendanceDayId);
        $existingGrant = CompensatoryLeaveGrant::query()
            ->where('attendance_day_id', $command->attendanceDayId)
            ->first();

        // status='confirmed'(月次提出済み)のGrantは触らない。締め後の実績変更との整合性は
        // 月次確認画面の警告(AttendanceMonthResource::compensatory_leave_warnings)で扱う。
        if ($existingGrant !== null && $existingGrant->status !== CompensatoryLeaveGrantStatus::DRAFT) {
            return null;
        }

        $isHolidayWork = $day !== null
            && in_array($day->day_classification, [DayClassification::PRESCRIBED_HOLIDAY, DayClassification::LEGAL_HOLIDAY], true)
            && ($day->calculation?->work_minutes ?? 0) > 0;

        if (! $isHolidayWork) {
            if ($existingGrant !== null) {
                CompensatoryLeaveGrantAggregate::retrieve($existingGrant->id)
                    ->remove('休日出勤の実績が取り消されたため')
                    ->persist();
            }

            return null;
        }

        [$grantedDays, $grantedMinutes] = CompensatoryLeaveGrantCalculator::resolveGrantedAmount($settings, (int) $day->calculation->work_minutes);

        $grantId = $existingGrant->id ?? (string) Str::uuid();

        CompensatoryLeaveGrantAggregate::retrieve($grantId)
            ->sync(
                userId: $day->user_id,
                attendanceDayId: $day->id,
                workDate: $day->work_date->toDateString(),
                grantedDays: $grantedDays,
                grantedMinutes: $grantedMinutes,
            )
            ->persist();

        return null;
    }
}
