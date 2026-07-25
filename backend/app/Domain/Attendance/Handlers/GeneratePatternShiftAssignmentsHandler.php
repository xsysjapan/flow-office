<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeShiftAssignmentAggregate;
use App\Domain\Attendance\Commands\GeneratePatternShiftAssignments;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\WeeklyPatternResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 週次・月次の一括入力(曜日ごとの既定値 + 日単位の上書き)を、指定期間の勤務予定へ
 * 展開する。既存の`EmployeeShiftAssigned`イベント・集約・Projectorを再利用し、
 * `GenerateRotationShiftAssignmentsHandler`と同じ安全策(実績のある日・締め済みの日は
 * 常にスキップ、`overwrite_mode=skip_edited`では個別上書き済みの日も保護)を踏襲する。
 *
 * `dayOverrides`にキーがある日から生成した行は`is_manually_overridden=true`にし、
 * 週次パターンのみから生成した行は`false`のままにする。これにより、月次で個別に
 * 変更した日だけが、後続の週次パターン再生成(`skip_edited`)から保護される。
 *
 * @implements CommandHandler<GeneratePatternShiftAssignments>
 */
class GeneratePatternShiftAssignmentsHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
    ) {}

    /**
     * @return array{generated: Collection<int, EmployeeShiftAssignment>, skipped_dates: list<string>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GeneratePatternShiftAssignments);

        $resolver = new WeeklyPatternResolver($command->weeklyPattern, $command->dayOverrides);

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $generated = collect();
        $skipped = [];

        foreach ($period as $date) {
            $resolved = $resolver->resolve($date);

            if ($resolved === null) {
                continue;
            }

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

            $existing = EmployeeShiftAssignment::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first();

            if ($existing?->is_manually_overridden && $command->overwriteMode === GeneratePatternShiftAssignments::OVERWRITE_MODE_SKIP_EDITED) {
                $skipped[] = $date->toDateString();

                continue;
            }

            $value = $resolved['value'];
            $isManuallyOverridden = $resolved['source'] === 'day_override';

            $plannedStartAt = $value !== null ? $date->copy()->setTimeFromTimeString($value['start_time']) : null;
            $plannedEndAt = $value !== null ? $date->copy()->setTimeFromTimeString($value['end_time']) : null;
            if ($plannedEndAt !== null && $plannedStartAt !== null && $plannedEndAt->lessThanOrEqualTo($plannedStartAt)) {
                $plannedEndAt = $plannedEndAt->addDay();
            }

            $id = $existing?->id ?? (string) Str::uuid();

            EmployeeShiftAssignmentAggregate::retrieve($id)
                ->assign(
                    userId: $command->userId,
                    workDate: $date->toDateString(),
                    workStyleId: $command->workStyleId,
                    shiftPatternId: null,
                    dayType: 'pattern',
                    isWorkingDay: $value !== null,
                    isLegalHoliday: false,
                    isCompanyHoliday: $value === null,
                    plannedStartAt: $plannedStartAt?->toIso8601String(),
                    plannedEndAt: $plannedEndAt?->toIso8601String(),
                    plannedBreakMinutes: $value['break_minutes'] ?? 0,
                    plannedBreakStartAt: null,
                    plannedBreakEndAt: null,
                    isPublished: false,
                    isManuallyOverridden: $isManuallyOverridden,
                    assignedByUserId: $command->generatedByUserId,
                )
                ->persist();

            $generated->push(EmployeeShiftAssignment::query()->findOrFail($id));
        }

        return ['generated' => $generated, 'skipped_dates' => $skipped];
    }
}
