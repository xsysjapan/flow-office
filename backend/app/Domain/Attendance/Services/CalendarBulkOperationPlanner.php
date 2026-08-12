<?php

namespace App\Domain\Attendance\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\CalendarBulkOperation;
use App\Models\EmployeeCalendarEntry;
use App\Models\SystemSetting;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * UC-C013: 複数従業員予定の一括操作(`calendar_bulk_operations`)の対象特定・適用内容の計算。
 *
 * プレビュー(何も保存しない)と確定適用(`CalendarBulkOperationAggregate::apply`経由)の
 * どちらからも同じ計算ロジックを使う(プレビュー→確定適用で結果がずれないようにするため)。
 *
 * - `calendar_apply`: `CalendarDayScheduleResolver`(`GenerateEmployeeCalendarEntriesHandler`と
 *   共有、会社カレンダー日の`schedule_state`から所定時刻を計算する)を呼ぶ。
 * - `rotation_generate`: `RotationScheduleResolver`(`GenerateRotationCalendarEntriesHandler`と
 *   共有、ローテーション基準からシフトパターンを割り出す)を呼ぶ。
 * - `bulk_edit`: `target_scope.entries`で個別に指定された`schedule_state`/`entry_type`を
 *   そのまま使う。
 *
 * 計算ロジック自体をHandlerと複製しない(CLAUDE.md原則9)。既存Handler(UC-C003・UC-C008)の
 * 計算条件(公開済み年度のみに絞るか等)とこのPlannerの条件が異なる箇所は、Resolver呼び出しの
 * 引数(`onlyPublished`等)で表現し、Resolver内部のロジックは完全に共有する。
 */
class CalendarBulkOperationPlanner
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
        private readonly CalendarDayScheduleResolver $calendarDayScheduleResolver,
        private readonly RotationScheduleResolver $rotationScheduleResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $targetScope
     * @return array{targets: list<array<string, mixed>>, conflict_count: int, executable: bool}
     */
    public function plan(string $operationType, array $targetScope, string $conflictPolicy): array
    {
        $rawTargets = match ($operationType) {
            CalendarBulkOperation::OPERATION_CALENDAR_APPLY => $this->planCalendarApply($targetScope),
            CalendarBulkOperation::OPERATION_ROTATION_GENERATE => $this->planRotationGenerate($targetScope),
            CalendarBulkOperation::OPERATION_BULK_EDIT => $this->planBulkEdit($targetScope),
            default => throw new DomainRuleException("未知のoperation_typeです: {$operationType}"),
        };

        $conflictCount = 0;
        $targets = [];

        foreach ($rawTargets as $target) {
            if ($target['conflict']) {
                $conflictCount++;
            }

            $targets[] = $target;
        }

        $executable = ! ($conflictPolicy === 'fail_on_conflict' && $conflictCount > 0);

        foreach ($targets as &$target) {
            $target['result'] = $this->resolveResult($target, $conflictPolicy, $executable);
        }

        return ['targets' => $targets, 'conflict_count' => $conflictCount, 'executable' => $executable];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resolveResult(array $target, string $conflictPolicy, bool $executable): string
    {
        if (! $executable) {
            return 'failed';
        }

        if ($target['guard_blocked']) {
            return 'skipped_existing';
        }

        if ($conflictPolicy === 'overwrite') {
            return 'applied';
        }

        // skip_existing / fail_on_conflict(競合が無い場合のみここに来る)。
        return $target['conflict'] ? 'skipped_existing' : 'applied';
    }

    /**
     * @param  array<string, mixed>  $targetScope
     * @return list<array<string, mixed>>
     */
    private function planCalendarApply(array $targetScope): array
    {
        $workStyle = WorkStyle::query()->findOrFail($targetScope['work_style_id']);
        $from = $targetScope['from'];
        $to = $targetScope['to'];

        $calendarDaysByDate = $this->calendarDayScheduleResolver->calendarDaysForRange(
            $workStyle->company_calendar_id,
            $from,
            $to,
            onlyPublished: true,
        );

        $targets = [];

        foreach ($targetScope['user_ids'] as $userId) {
            $period = Carbon::parse($from)->toPeriod(Carbon::parse($to));

            foreach ($period as $date) {
                $dateString = $date->toDateString();
                $calendarDay = $calendarDaysByDate->get($dateString);
                $schedule = $this->calendarDayScheduleResolver->resolve($workStyle, $date, $calendarDay);

                $existing = $this->findExisting($userId, $dateString);

                $targets[] = [
                    'user_id' => $userId,
                    'work_date' => $dateString,
                    'conflict' => $existing !== null,
                    'guard_blocked' => $this->isGuardBlocked($userId, $dateString),
                    'attributes' => [
                        'work_style_id' => $workStyle->id,
                        'shift_pattern_id' => null,
                        'day_type' => $schedule['day_type'],
                        'is_working_day' => $schedule['is_working_day'],
                        'is_legal_holiday' => $schedule['is_legal_holiday'],
                        'is_company_holiday' => $schedule['is_company_holiday'],
                        'planned_start_at' => $schedule['planned_start_at']?->toIso8601String(),
                        'planned_end_at' => $schedule['planned_end_at']?->toIso8601String(),
                        'planned_break_minutes' => $schedule['planned_break_minutes'],
                        'planned_break_start_at' => null,
                        'planned_break_end_at' => null,
                        'is_published' => true,
                        'is_manually_overridden' => false,
                        'schedule_state' => $schedule['schedule_state'],
                        'entry_type' => 'OVERRIDE',
                        'source_type' => 'bulk_operation',
                    ],
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $targetScope
     * @return list<array<string, mixed>>
     */
    private function planRotationGenerate(array $targetScope): array
    {
        $from = $targetScope['from'];
        $to = $targetScope['to'];
        $targets = [];

        foreach ($targetScope['user_ids'] as $userId) {
            $rotationAssignment = $this->rotationScheduleResolver->assignmentFor($userId);

            if ($rotationAssignment === null) {
                continue;
            }

            $pattern = $rotationAssignment->rotationPattern;
            $period = Carbon::parse($from)->toPeriod(Carbon::parse($to));

            foreach ($period as $date) {
                $dateString = $date->toDateString();
                $shiftPattern = $this->rotationScheduleResolver->shiftPatternFor($rotationAssignment, $date);

                if ($shiftPattern === null) {
                    continue;
                }

                $schedule = $this->rotationScheduleResolver->resolve($pattern, $shiftPattern, $date);
                $existing = $this->findExisting($userId, $dateString);

                $targets[] = [
                    'user_id' => $userId,
                    'work_date' => $dateString,
                    // UC-C008固有の判定: 行の有無ではなくis_manually_overriddenの値を見る。
                    'conflict' => (bool) $existing?->is_manually_overridden,
                    'guard_blocked' => $this->isGuardBlocked($userId, $dateString),
                    'attributes' => [
                        'work_style_id' => $schedule['work_style_id'],
                        'shift_pattern_id' => $schedule['shift_pattern_id'],
                        'day_type' => $schedule['day_type'],
                        'is_working_day' => $schedule['is_working_day'],
                        'is_legal_holiday' => $schedule['is_legal_holiday'],
                        'is_company_holiday' => $schedule['is_company_holiday'],
                        'planned_start_at' => $schedule['planned_start_at']?->toIso8601String(),
                        'planned_end_at' => $schedule['planned_end_at']?->toIso8601String(),
                        'planned_break_minutes' => $schedule['planned_break_minutes'],
                        'planned_break_start_at' => null,
                        'planned_break_end_at' => null,
                        'is_published' => false,
                        'is_manually_overridden' => false,
                        'schedule_state' => $schedule['schedule_state'],
                        'entry_type' => 'SHIFT_ASSIGNMENT',
                        'source_type' => 'bulk_operation',
                    ],
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $targetScope
     * @return list<array<string, mixed>>
     */
    private function planBulkEdit(array $targetScope): array
    {
        $targets = [];

        foreach ($targetScope['entries'] as $entry) {
            $existing = $this->findExisting($entry['user_id'], $entry['work_date']);
            $workStyleId = $entry['work_style_id'] ?? $existing?->work_style_id ?? SystemSetting::current()->default_work_style_id;

            $targets[] = [
                'user_id' => $entry['user_id'],
                'work_date' => $entry['work_date'],
                'conflict' => $existing !== null,
                'guard_blocked' => $this->isGuardBlocked($entry['user_id'], $entry['work_date']),
                'attributes' => [
                    'work_style_id' => $workStyleId,
                    'shift_pattern_id' => $existing?->shift_pattern_id,
                    'day_type' => $existing?->day_type ?? 'weekday',
                    'is_working_day' => $entry['schedule_state'] === 'WORK',
                    'is_legal_holiday' => $existing?->is_legal_holiday ?? false,
                    'is_company_holiday' => $existing?->is_company_holiday ?? false,
                    'planned_start_at' => $existing?->planned_start_at?->toIso8601String(),
                    'planned_end_at' => $existing?->planned_end_at?->toIso8601String(),
                    'planned_break_minutes' => $existing?->planned_break_minutes ?? 0,
                    'planned_break_start_at' => $existing?->planned_break_start_at?->toIso8601String(),
                    'planned_break_end_at' => $existing?->planned_break_end_at?->toIso8601String(),
                    'is_published' => $existing?->is_published ?? true,
                    'is_manually_overridden' => true,
                    'schedule_state' => $entry['schedule_state'],
                    'entry_type' => $entry['entry_type'] ?? 'MANUAL_ADJUSTMENT',
                    'source_type' => 'bulk_operation',
                ],
            ];
        }

        return $targets;
    }

    private function findExisting(string $userId, string $workDate): ?EmployeeCalendarEntry
    {
        return EmployeeCalendarEntry::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();
    }

    private function isGuardBlocked(string $userId, string $workDate): bool
    {
        $hasActualAttendance = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->whereNotNull('actual_start_at')
            ->exists();

        return $hasActualAttendance || ! $this->guard->isMutable(null, $userId, $workDate);
    }
}
