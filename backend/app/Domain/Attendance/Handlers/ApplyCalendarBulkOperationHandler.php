<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\CalendarBulkOperationAggregate;
use App\Domain\Attendance\Aggregates\EmployeeCalendarEntryAggregate;
use App\Domain\Attendance\Commands\ApplyCalendarBulkOperation;
use App\Domain\Attendance\Services\CalendarBulkOperationPlanner;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CalendarBulkOperation;
use App\Models\EmployeeCalendarEntry;
use Illuminate\Support\Str;

/**
 * UC-C013 手順3〜5: 複数従業員予定の一括操作を確定適用する。
 *
 * @implements CommandHandler<ApplyCalendarBulkOperation>
 */
class ApplyCalendarBulkOperationHandler implements CommandHandler
{
    public function __construct(
        private readonly CalendarBulkOperationPlanner $planner,
    ) {}

    public function handle(Command $command): CalendarBulkOperation
    {
        assert($command instanceof ApplyCalendarBulkOperation);

        $plan = $this->planner->plan($command->operationType, $command->targetScope, $command->conflictPolicy);

        if (! $plan['executable']) {
            throw new DomainRuleException('対象範囲内に既存の従業員予定との競合があるため、一括操作全体を実行できません(fail_on_conflict)。');
        }

        // 先にIDを確定しておき、employee_calendar_entry.assigned側のbulk_operation_idへ
        // 最初から正しい値を持たせる(Projector再生時にも一括操作由来の行を特定できるようにする)。
        $bulkOperationId = (string) Str::uuid();

        $targets = [];

        foreach ($plan['targets'] as $target) {
            if ($target['result'] !== 'applied') {
                $targets[] = [
                    'user_id' => $target['user_id'],
                    'work_date' => $target['work_date'],
                    'employee_calendar_entry_id' => null,
                    'result' => $target['result'],
                    'error_code' => null,
                    'previous_snapshot' => null,
                ];

                continue;
            }

            $existing = EmployeeCalendarEntry::query()
                ->where('user_id', $target['user_id'])
                ->whereDate('work_date', $target['work_date'])
                ->first();

            $previousSnapshot = $existing === null ? null : $this->snapshot($existing);
            $id = $existing?->id ?? (string) Str::uuid();
            $attributes = $target['attributes'];

            EmployeeCalendarEntryAggregate::retrieve($id)
                ->assign(
                    userId: $target['user_id'],
                    workDate: $target['work_date'],
                    workStyleId: $attributes['work_style_id'],
                    shiftPatternId: $attributes['shift_pattern_id'],
                    dayType: $attributes['day_type'],
                    isWorkingDay: $attributes['is_working_day'],
                    isLegalHoliday: $attributes['is_legal_holiday'],
                    isCompanyHoliday: $attributes['is_company_holiday'],
                    plannedStartAt: $attributes['planned_start_at'],
                    plannedEndAt: $attributes['planned_end_at'],
                    plannedBreakMinutes: $attributes['planned_break_minutes'],
                    plannedBreakStartAt: $attributes['planned_break_start_at'],
                    plannedBreakEndAt: $attributes['planned_break_end_at'],
                    isPublished: $attributes['is_published'],
                    isManuallyOverridden: $attributes['is_manually_overridden'],
                    assignedByUserId: $command->requestedByUserId,
                    scheduleState: $attributes['schedule_state'],
                    entryType: $attributes['entry_type'],
                    sourceType: $attributes['source_type'],
                    bulkOperationId: $bulkOperationId,
                )
                ->persist();

            $targets[] = [
                'user_id' => $target['user_id'],
                'work_date' => $target['work_date'],
                'employee_calendar_entry_id' => $id,
                'result' => 'applied',
                'error_code' => null,
                'previous_snapshot' => $previousSnapshot,
            ];
        }

        CalendarBulkOperationAggregate::retrieve($bulkOperationId)
            ->applyOperation(
                operationType: $command->operationType,
                targetScope: $command->targetScope,
                conflictPolicy: $command->conflictPolicy,
                reason: $command->reason,
                targets: $targets,
                requestedByUserId: $command->requestedByUserId,
            )
            ->persist();

        return CalendarBulkOperation::query()->findOrFail($bulkOperationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EmployeeCalendarEntry $entry): array
    {
        return [
            'work_style_id' => $entry->work_style_id,
            'shift_pattern_id' => $entry->shift_pattern_id,
            'day_type' => $entry->day_type,
            'is_working_day' => $entry->is_working_day,
            'is_legal_holiday' => $entry->is_legal_holiday,
            'is_company_holiday' => $entry->is_company_holiday,
            'planned_start_at' => $entry->planned_start_at?->toIso8601String(),
            'planned_end_at' => $entry->planned_end_at?->toIso8601String(),
            'planned_break_minutes' => $entry->planned_break_minutes,
            'planned_break_start_at' => $entry->planned_break_start_at?->toIso8601String(),
            'planned_break_end_at' => $entry->planned_break_end_at?->toIso8601String(),
            'is_published' => $entry->is_published,
            'is_manually_overridden' => $entry->is_manually_overridden,
            'schedule_state' => $entry->schedule_state,
            'entry_type' => $entry->entry_type,
            'source_type' => $entry->source_type,
            'bulk_operation_id' => $entry->bulk_operation_id,
        ];
    }
}
