<?php

namespace App\Domain\Attendance\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\CalendarBulkOperation;
use App\Models\CompanyCalendarDay;
use App\Models\EmployeeCalendarEntry;
use App\Models\EmployeeRotationAssignment;
use App\Models\SystemSetting;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * UC-C013: 複数従業員予定の一括操作(`calendar_bulk_operations`)の対象特定・適用内容の計算。
 *
 * プレビュー(何も保存しない)と確定適用(`CalendarBulkOperationAggregate::apply`経由)の
 * どちらからも同じ計算ロジックを使う(プレビュー→確定適用で結果がずれないようにするため)。
 *
 * - `calendar_apply`: 既存`GenerateEmployeeCalendarEntriesHandler`と同じ考え方(会社カレンダー
 *   日の`schedule_state`から所定時刻を計算する)を、ここでも独立に計算する(既存コマンド自体は
 *   変更しない)。
 * - `rotation_generate`: 既存`GenerateRotationCalendarEntriesHandler`と同じ考え方
 *   (ローテーション基準からシフトパターンを割り出す)。
 * - `bulk_edit`: `target_scope.entries`で個別に指定された`schedule_state`/`entry_type`を
 *   そのまま使う。
 */
class CalendarBulkOperationPlanner
{
    public function __construct(
        private readonly AttendanceEditGuard $guard,
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

        $calendarDaysByDate = $workStyle->company_calendar_id === null
            ? collect()
            : CompanyCalendarDay::query()
                ->whereHas('year', fn ($q) => $q->where('company_calendar_id', $workStyle->company_calendar_id)->where('status', 'published'))
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->get()
                ->keyBy(fn ($day) => $day->date->toDateString());

        $targets = [];

        foreach ($targetScope['user_ids'] as $userId) {
            $period = Carbon::parse($from)->toPeriod(Carbon::parse($to));

            foreach ($period as $date) {
                $dateString = $date->toDateString();
                $calendarDay = $calendarDaysByDate->get($dateString);
                $isWorkingDay = $calendarDay?->is_working_day ?? true;
                $scheduleState = $calendarDay?->schedule_state ?? ($isWorkingDay ? 'WORK' : 'OFF');

                $plannedStartAt = $isWorkingDay && $workStyle->default_start_time
                    ? $date->copy()->setTimeFromTimeString($workStyle->default_start_time) : null;
                $plannedEndAt = $isWorkingDay && $workStyle->default_end_time
                    ? $date->copy()->setTimeFromTimeString($workStyle->default_end_time) : null;

                $existing = $this->findExisting($userId, $dateString);

                $targets[] = [
                    'user_id' => $userId,
                    'work_date' => $dateString,
                    'conflict' => $existing !== null,
                    'guard_blocked' => $this->isGuardBlocked($userId, $dateString),
                    'attributes' => [
                        'work_style_id' => $workStyle->id,
                        'shift_pattern_id' => null,
                        'day_type' => $calendarDay?->day_type ?? 'weekday',
                        'is_working_day' => $isWorkingDay,
                        'is_legal_holiday' => $calendarDay?->is_legal_holiday ?? false,
                        'is_company_holiday' => $calendarDay?->is_company_holiday ?? false,
                        'planned_start_at' => $plannedStartAt?->toIso8601String(),
                        'planned_end_at' => $plannedEndAt?->toIso8601String(),
                        'planned_break_minutes' => $isWorkingDay ? $workStyle->default_break_minutes : 0,
                        'planned_break_start_at' => null,
                        'planned_break_end_at' => null,
                        'is_published' => true,
                        'is_manually_overridden' => false,
                        'schedule_state' => $scheduleState,
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
            $rotationAssignment = EmployeeRotationAssignment::query()
                ->where('user_id', $userId)
                ->with('rotationPattern.items.shiftPattern')
                ->first();

            if ($rotationAssignment === null) {
                continue;
            }

            $pattern = $rotationAssignment->rotationPattern;
            $itemsBySequence = $pattern->items->keyBy('sequence');
            $period = Carbon::parse($from)->toPeriod(Carbon::parse($to));

            foreach ($period as $date) {
                $dateString = $date->toDateString();
                $sequenceIndex = $rotationAssignment->sequenceIndexFor($date, $pattern->cycle_length);
                $item = $itemsBySequence->get($sequenceIndex);

                if ($item === null) {
                    continue;
                }

                $shiftPattern = $item->shiftPattern;
                $existing = $this->findExisting($userId, $dateString);

                $plannedStartAt = $shiftPattern->start_time ? $date->copy()->setTimeFromTimeString($shiftPattern->start_time) : null;
                $plannedEndAt = $shiftPattern->end_time ? $date->copy()->setTimeFromTimeString($shiftPattern->end_time) : null;
                if ($plannedEndAt !== null && $shiftPattern->crosses_midnight) {
                    $plannedEndAt = $plannedEndAt->addDay();
                }

                $targets[] = [
                    'user_id' => $userId,
                    'work_date' => $dateString,
                    // UC-C008固有の判定: 行の有無ではなくis_manually_overriddenの値を見る。
                    'conflict' => (bool) $existing?->is_manually_overridden,
                    'guard_blocked' => $this->isGuardBlocked($userId, $dateString),
                    'attributes' => [
                        'work_style_id' => $pattern->work_style_id,
                        'shift_pattern_id' => $shiftPattern->id,
                        'day_type' => $shiftPattern->code,
                        'is_working_day' => $shiftPattern->isWorkingPattern(),
                        'is_legal_holiday' => false,
                        'is_company_holiday' => ! $shiftPattern->isWorkingPattern(),
                        'planned_start_at' => $plannedStartAt?->toIso8601String(),
                        'planned_end_at' => $plannedEndAt?->toIso8601String(),
                        'planned_break_minutes' => $shiftPattern->break_minutes,
                        'planned_break_start_at' => null,
                        'planned_break_end_at' => null,
                        'is_published' => false,
                        'is_manually_overridden' => false,
                        'schedule_state' => $shiftPattern->isWorkingPattern() ? 'WORK' : 'OFF',
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
