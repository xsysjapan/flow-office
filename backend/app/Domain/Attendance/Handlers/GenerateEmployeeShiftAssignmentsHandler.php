<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeShiftAssignmentAggregate;
use App\Domain\Attendance\Commands\GenerateEmployeeShiftAssignments;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * UC-C003: 働き方ごとのカレンダーをもとに、指定期間分の勤務予定を一括生成する。
 *
 * 生成対象日ごとに個別のEmployeeShiftAssigned(employee_shift.assigned)イベントを発行する
 * (バッチ全体で1イベントにまとめない)。理由: 生成された行はその後
 * EditEmployeeShiftAssignment(勤務予定の個別編集)やAssignShiftPatternDay(3交代制の
 * 日別パターン割当)から個別の行idを指定して後続コマンドの対象になるため、各行を
 * 独立して取得・再生できる必要がある。
 *
 * @implements CommandHandler<GenerateEmployeeShiftAssignments>
 */
class GenerateEmployeeShiftAssignmentsHandler implements CommandHandler
{
    public function handle(Command $command): Collection
    {
        assert($command instanceof GenerateEmployeeShiftAssignments);

        $workStyle = WorkStyle::query()->with('calendar.days')->findOrFail($command->workStyleId);
        $calendarDaysByDate = $workStyle->calendar?->days->keyBy(fn ($day) => $day->date->toDateString()) ?? collect();

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $assignments = collect();

        foreach ($period as $date) {
            $calendarDay = $calendarDaysByDate->get($date->toDateString());
            $isWorkingDay = $calendarDay?->is_working_day ?? true;

            // 'work_date' はdateキャストのためDB上はdatetime文字列で保存される。
            // 厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $existing = EmployeeShiftAssignment::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first();

            $id = $existing?->id ?? (string) Str::uuid();

            $plannedStartAt = $isWorkingDay && $workStyle->default_start_time
                ? $date->copy()->setTimeFromTimeString($workStyle->default_start_time) : null;
            $plannedEndAt = $isWorkingDay && $workStyle->default_end_time
                ? $date->copy()->setTimeFromTimeString($workStyle->default_end_time) : null;
            $plannedBreakMinutes = $isWorkingDay ? $workStyle->default_break_minutes : 0;
            $plannedBreakStartAt = $isWorkingDay && $workStyle->default_break_start_time
                ? $date->copy()->setTimeFromTimeString($workStyle->default_break_start_time) : null;
            $plannedBreakEndAt = $isWorkingDay && $workStyle->default_break_end_time
                ? $date->copy()->setTimeFromTimeString($workStyle->default_break_end_time) : null;

            EmployeeShiftAssignmentAggregate::retrieve($id)
                ->assign(
                    userId: $command->userId,
                    workDate: $date->toDateString(),
                    workStyleId: $workStyle->id,
                    shiftPatternId: null,
                    dayType: $calendarDay?->day_type ?? 'weekday',
                    isWorkingDay: $isWorkingDay,
                    isLegalHoliday: $calendarDay?->is_legal_holiday ?? false,
                    isCompanyHoliday: $calendarDay?->is_company_holiday ?? false,
                    plannedStartAt: $plannedStartAt?->toIso8601String(),
                    plannedEndAt: $plannedEndAt?->toIso8601String(),
                    plannedBreakMinutes: $plannedBreakMinutes,
                    plannedBreakStartAt: $plannedBreakStartAt?->toIso8601String(),
                    plannedBreakEndAt: $plannedBreakEndAt?->toIso8601String(),
                    // カレンダー基準の一括生成は下書き公開の概念を持たず、従来通り即時有効にする。
                    isPublished: true,
                    // 旧実装はこのフィールドに触れず既存行の値を保持していた(個別上書き済みの日を
                    // 一括生成が壊さないようにするため)。イベントから行を完全に再構築できるよう、
                    // その「触れない」結果を明示的な値としてイベントに持たせる。
                    isManuallyOverridden: $existing?->is_manually_overridden ?? false,
                    assignedByUserId: $command->generatedByUserId,
                )
                ->persist();

            $assignments->push(EmployeeShiftAssignment::query()->findOrFail($id));
        }

        return $assignments;
    }
}
