<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\EmployeeCalendarEntryAssigned;
use App\Domain\Attendance\Events\EmployeeCalendarEntryPlanChanged;
use App\Domain\Attendance\Events\EmployeeCalendarEntryPublished;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * employee_calendar_entry集約。主キー(employee_calendar_entries.id)はコマンド側/呼び出し元
 * サービスが決めたUUIDで、行の新規作成自体はEmployeeCalendarEntryProjectorに委ねられる。
 * 業務ルール判定(既に勤務実績がある日か等)はHandlerがProjection(Eloquent)の現在値を
 * 読んで行う(他ドメインと同じ理由。集約の再生状態を判定には使わない)。
 */
class EmployeeCalendarEntryAggregate extends AggregateRoot
{
    public function assign(
        string $userId,
        string $workDate,
        ?string $workStyleId,
        ?string $shiftPatternId,
        string $dayType,
        bool $isWorkingDay,
        bool $isLegalHoliday,
        bool $isCompanyHoliday,
        ?string $plannedStartAt,
        ?string $plannedEndAt,
        int $plannedBreakMinutes,
        ?string $plannedBreakStartAt,
        ?string $plannedBreakEndAt,
        bool $isPublished,
        bool $isManuallyOverridden,
        string $assignedByUserId,
        ?string $scheduleState = null,
        ?string $entryType = null,
        ?string $sourceType = null,
        ?string $bulkOperationId = null,
    ): self {
        $this->recordThat(new EmployeeCalendarEntryAssigned(
            userId: $userId,
            workDate: $workDate,
            workStyleId: $workStyleId,
            shiftPatternId: $shiftPatternId,
            dayType: $dayType,
            isWorkingDay: $isWorkingDay,
            isLegalHoliday: $isLegalHoliday,
            isCompanyHoliday: $isCompanyHoliday,
            plannedStartAt: $plannedStartAt,
            plannedEndAt: $plannedEndAt,
            plannedBreakMinutes: $plannedBreakMinutes,
            plannedBreakStartAt: $plannedBreakStartAt,
            plannedBreakEndAt: $plannedBreakEndAt,
            isPublished: $isPublished,
            isManuallyOverridden: $isManuallyOverridden,
            assignedByUserId: $assignedByUserId,
            scheduleState: $scheduleState,
            entryType: $entryType,
            sourceType: $sourceType,
            bulkOperationId: $bulkOperationId,
        ));

        return $this;
    }

    public function changePlan(
        ?string $previousPlannedStartAt,
        ?string $previousPlannedEndAt,
        int $previousPlannedBreakMinutes,
        ?string $plannedStartAt,
        ?string $plannedEndAt,
        int $plannedBreakMinutes,
        string $reason,
        string $editedByUserId,
    ): self {
        $this->recordThat(new EmployeeCalendarEntryPlanChanged(
            previousPlannedStartAt: $previousPlannedStartAt,
            previousPlannedEndAt: $previousPlannedEndAt,
            previousPlannedBreakMinutes: $previousPlannedBreakMinutes,
            plannedStartAt: $plannedStartAt,
            plannedEndAt: $plannedEndAt,
            plannedBreakMinutes: $plannedBreakMinutes,
            reason: $reason,
            editedByUserId: $editedByUserId,
        ));

        return $this;
    }

    public function publish(string $userId, string $workDate, string $publishedByUserId): self
    {
        $this->recordThat(new EmployeeCalendarEntryPublished(
            userId: $userId,
            workDate: $workDate,
            publishedByUserId: $publishedByUserId,
        ));

        return $this;
    }
}
