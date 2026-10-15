<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\RecalculateAttendanceDailyCalculation;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;

/**
 * 1日分の日次計算を現在の計算ロジックで記録し直す(日次計算のみ。打刻・勤怠実績・月次の提出時スナップショットは変えない)。
 *
 * @implements CommandHandler<RecalculateAttendanceDailyCalculation>
 */
class RecalculateAttendanceDailyCalculationHandler implements CommandHandler
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RecalculateAttendanceDailyCalculation);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        $isManuallyAdjusted = AttendanceDailyCalculation::query()
            ->where('attendance_day_id', $day->id)
            ->where('is_manually_adjusted', true)
            ->exists();
        if ($isManuallyAdjusted) {
            throw new DomainRuleException('手動調整済みの日次計算は一括再計算の対象外です。');
        }

        $day->load('breaks', 'leaveSegments', 'calendarEntry.workStyle');

        AttendanceDayAggregate::retrieve($day->id)
            ->calculate($this->calculator->calculate($day), $day->user_id, $day->work_date->toDateString())
            ->persist();

        return null;
    }
}
