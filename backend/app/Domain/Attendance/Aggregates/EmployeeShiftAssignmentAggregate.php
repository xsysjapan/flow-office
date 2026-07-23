<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\EmployeeShiftAssigned;
use App\Domain\Attendance\Events\EmployeeShiftPlanChanged;
use App\Domain\Attendance\Events\EmployeeShiftPublished;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * employee_shift_assignment集約。主キー(employee_shift_assignments.id)はコマンド側/呼び出し元
 * サービスが決めたUUIDで、行の新規作成自体はEmployeeShiftAssignmentProjectorに委ねられる。
 * 業務ルール判定(既に勤務実績がある日か等)はHandlerがProjection(Eloquent)の現在値を
 * 読んで行う(他ドメインと同じ理由。集約の再生状態を判定には使わない)。
 */
class EmployeeShiftAssignmentAggregate extends AggregateRoot
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
    ): self {
        $this->recordThat(new EmployeeShiftAssigned(
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
        $this->recordThat(new EmployeeShiftPlanChanged(
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
        $this->recordThat(new EmployeeShiftPublished(
            userId: $userId,
            workDate: $workDate,
            publishedByUserId: $publishedByUserId,
        ));

        return $this;
    }
}
