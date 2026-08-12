<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\Attendance\Commands\GenerateEmployeeCalendarEntries;
use App\Domain\Attendance\Services\CalendarDayScheduleResolver;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\EmployeeCalendarEntry;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * UC-C003: 働き方ごとのカレンダーをもとに、指定期間分の勤務予定を一括生成する。
 *
 * 生成対象日ごとに個別のEmployeeCalendarEntryAssigned(employee_calendar_entry.assigned)イベントを発行する
 * (バッチ全体で1イベントにまとめない)。理由: 生成された行はその後
 * EditEmployeeCalendarEntry(勤務予定の個別編集)やAssignShiftPatternDay(3交代制の
 * 日別パターン割当)から個別の行idを指定して後続コマンドの対象になるため、各行を
 * 独立して取得・再生できる必要がある。
 *
 * @implements CommandHandler<GenerateEmployeeCalendarEntries>
 */
class GenerateEmployeeCalendarEntriesHandler implements CommandHandler
{
    public function __construct(
        private readonly CalendarDayScheduleResolver $scheduleResolver,
    ) {}

    public function handle(Command $command): Collection
    {
        assert($command instanceof GenerateEmployeeCalendarEntries);

        $workStyle = WorkStyle::query()->findOrFail($command->workStyleId);

        // 会社カレンダー日はcompany_calendar_years配下にあるため、本体(company_calendar_id)
        // ではなく対象期間が属する年度を経由して取得する(複数年度をまたぐ期間も対応する)。
        // 旧実装は公開ステータスで絞っていなかったため、この呼び出しでも絞らない。
        $calendarDaysByDate = $this->scheduleResolver->calendarDaysForRange(
            $workStyle->company_calendar_id,
            $command->from,
            $command->to,
            onlyPublished: false,
        );

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $assignments = collect();

        foreach ($period as $date) {
            $calendarDay = $calendarDaysByDate->get($date->toDateString());
            $schedule = $this->scheduleResolver->resolve($workStyle, $date, $calendarDay);

            // 'work_date' はdateキャストのためDB上はdatetime文字列で保存される。
            // 厳密一致検索では既存行を見つけられないため、whereDateで明示的に検索する。
            $existing = EmployeeCalendarEntry::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first();

            $id = $existing?->id ?? (string) Str::uuid();

            EmployeeCalendarEntryAggregate::retrieve($id)
                ->assign(
                    userId: $command->userId,
                    workDate: $date->toDateString(),
                    workStyleId: $workStyle->id,
                    shiftPatternId: null,
                    dayType: $schedule['day_type'],
                    isWorkingDay: $schedule['is_working_day'],
                    isLegalHoliday: $schedule['is_legal_holiday'],
                    isCompanyHoliday: $schedule['is_company_holiday'],
                    plannedStartAt: $schedule['planned_start_at']?->toIso8601String(),
                    plannedEndAt: $schedule['planned_end_at']?->toIso8601String(),
                    plannedBreakMinutes: $schedule['planned_break_minutes'],
                    plannedBreakStartAt: $schedule['planned_break_start_at']?->toIso8601String(),
                    plannedBreakEndAt: $schedule['planned_break_end_at']?->toIso8601String(),
                    // カレンダー基準の一括生成は下書き公開の概念を持たず、従来通り即時有効にする。
                    isPublished: true,
                    // 旧実装はこのフィールドに触れず既存行の値を保持していた(個別上書き済みの日を
                    // 一括生成が壊さないようにするため)。イベントから行を完全に再構築できるよう、
                    // その「触れない」結果を明示的な値としてイベントに持たせる。
                    isManuallyOverridden: $existing?->is_manually_overridden ?? false,
                    assignedByUserId: $command->generatedByUserId,
                )
                ->persist();

            $assignments->push(EmployeeCalendarEntry::query()->findOrFail($id));
        }

        return $assignments;
    }
}
