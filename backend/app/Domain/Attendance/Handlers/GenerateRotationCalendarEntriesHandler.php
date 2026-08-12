<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\Attendance\Commands\GenerateRotationCalendarEntries;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\RotationScheduleResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 指示書 8.7節・8.8節: 社員に割り当てられたローテーション基準から、指定期間分の勤務予定を
 * 一括生成する。生成は冪等ではあるが、次の日は自動上書きしない(安全な既定値)。
 *
 * - 既に勤務実績(打刻・実績入力)がある日、または月次が承認済み以降でロックされている日
 * - `overwrite_mode=skip_edited`(既定)の場合、個別に上書き済み(`is_manually_overridden`)の日
 *
 * `overwrite_mode=overwrite_all`は個別上書き済みの日だけを再生成し直す用途で、実績のある日・
 * ロックされた日は安全のためこのモードでも常にスキップする。
 *
 * @implements CommandHandler<GenerateRotationCalendarEntries>
 */
class GenerateRotationCalendarEntriesHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly RotationScheduleResolver $scheduleResolver,
    ) {}

    /**
     * @return array{generated: Collection<int, EmployeeCalendarEntry>, skipped_dates: list<string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GenerateRotationCalendarEntries);

        $rotationAssignment = $this->scheduleResolver->assignmentFor($command->userId);

        if ($rotationAssignment === null) {
            throw new DomainRuleException('この社員にはローテーションが割り当てられていません。');
        }

        $pattern = $rotationAssignment->rotationPattern;

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $generated = collect();
        $skipped = [];

        foreach ($period as $date) {
            $hasActualAttendance = AttendanceDay::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->whereNotNull('actual_start_at')
                ->exists();
            $isLocked = ! $this->guard->isMutable(null, $command->userId, $date->toDateString());

            if ($hasActualAttendance || $isLocked) {
                $skipped[] = $date->toDateString();

                continue;
            }

            $existing = EmployeeCalendarEntry::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first();

            if ($existing?->is_manually_overridden && $command->overwriteMode === GenerateRotationCalendarEntries::OVERWRITE_MODE_SKIP_EDITED) {
                $skipped[] = $date->toDateString();

                continue;
            }

            $shiftPattern = $this->scheduleResolver->shiftPatternFor($rotationAssignment, $date);

            if ($shiftPattern === null) {
                continue;
            }

            $schedule = $this->scheduleResolver->resolve($pattern, $shiftPattern, $date);

            $id = $existing?->id ?? (string) Str::uuid();

            EmployeeCalendarEntryAggregate::retrieve($id)
                ->assign(
                    userId: $command->userId,
                    workDate: $date->toDateString(),
                    workStyleId: $schedule['work_style_id'],
                    shiftPatternId: $schedule['shift_pattern_id'],
                    dayType: $schedule['day_type'],
                    isWorkingDay: $schedule['is_working_day'],
                    isLegalHoliday: $schedule['is_legal_holiday'],
                    isCompanyHoliday: $schedule['is_company_holiday'],
                    plannedStartAt: $schedule['planned_start_at']?->toIso8601String(),
                    plannedEndAt: $schedule['planned_end_at']?->toIso8601String(),
                    plannedBreakMinutes: $schedule['planned_break_minutes'],
                    plannedBreakStartAt: $schedule['planned_break_start_at']?->toIso8601String(),
                    plannedBreakEndAt: $schedule['planned_break_end_at']?->toIso8601String(),
                    isPublished: false,
                    isManuallyOverridden: false,
                    assignedByUserId: $command->generatedByUserId,
                )
                ->persist();

            $generated->push(EmployeeCalendarEntry::query()->findOrFail($id));
        }

        return ['generated' => $generated, 'skipped_dates' => $skipped];
    }
}
