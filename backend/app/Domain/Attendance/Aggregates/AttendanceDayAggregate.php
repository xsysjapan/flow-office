<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendanceBreakAutoInserted;
use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * attendance_day集約。主キー(attendance_days.id)はコマンド側/呼び出し元サービスが決めた
 * UUIDで、行の新規作成自体もAttendanceDayProjectorに委ねられる。業務ルール判定
 * (締め後・承認済み月次かどうか等)はAttendanceEditGuardがEloquent Projectionの現在値を
 * 読んで行う(他ドメインと同じ理由。集約の再生状態を判定には使わない)。
 */
class AttendanceDayAggregate extends AggregateRoot
{
    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     */
    public function create(
        string $userId,
        string $workDate,
        ?string $shiftAssignmentId,
        string $status,
        string $source,
        int $utcOffsetMinutes,
        ?string $actualStartAt,
        ?string $actualEndAt,
        ?string $workType,
        ?string $workLocationType,
        ?string $note,
        array $breaks,
        array $leaveSegments,
        string $reason,
        string $createdByUserId,
    ): self {
        $this->recordThat(new AttendanceDayCreated(
            userId: $userId,
            workDate: $workDate,
            shiftAssignmentId: $shiftAssignmentId,
            status: $status,
            source: $source,
            utcOffsetMinutes: $utcOffsetMinutes,
            actualStartAt: $actualStartAt,
            actualEndAt: $actualEndAt,
            workType: $workType,
            workLocationType: $workLocationType,
            note: $note,
            breaks: $breaks,
            leaveSegments: $leaveSegments,
            reason: $reason,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }

    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     */
    public function edit(
        int $utcOffsetMinutes,
        ?string $actualStartAt,
        ?string $actualEndAt,
        string $status,
        ?string $workType,
        ?string $workLocationType,
        bool $workLocationTypeProvided,
        ?string $note,
        array $breaks,
        array $leaveSegments,
        string $reason,
        string $editedByUserId,
    ): self {
        $this->recordThat(new AttendanceDayEdited(
            utcOffsetMinutes: $utcOffsetMinutes,
            actualStartAt: $actualStartAt,
            actualEndAt: $actualEndAt,
            status: $status,
            workType: $workType,
            workLocationType: $workLocationType,
            workLocationTypeProvided: $workLocationTypeProvided,
            note: $note,
            breaks: $breaks,
            leaveSegments: $leaveSegments,
            reason: $reason,
            editedByUserId: $editedByUserId,
        ));

        return $this;
    }

    /**
     * @param  array<string, int|bool|float|null>  $calculation
     */
    public function calculate(array $calculation): self
    {
        $this->recordThat(new AttendanceDayCalculated(calculation: $calculation));

        return $this;
    }

    public function adjustCalculation(
        int $prescribedWorkMinutes,
        int $statutoryWithinOvertimeMinutes,
        int $statutoryExcessOvertimeMinutes,
        int $legalHolidayWorkMinutes,
        int $prescribedHolidayWorkMinutes,
        int $lateNightPrescribedWorkMinutes,
        int $lateNightStatutoryWithinOvertimeMinutes,
        int $lateNightStatutoryExcessOvertimeMinutes,
        int $lateNightLegalHolidayWorkMinutes,
        string $reason,
        string $adjustedByUserId,
    ): self {
        $this->recordThat(new AttendanceDailyCalculationAdjusted(
            prescribedWorkMinutes: $prescribedWorkMinutes,
            statutoryWithinOvertimeMinutes: $statutoryWithinOvertimeMinutes,
            statutoryExcessOvertimeMinutes: $statutoryExcessOvertimeMinutes,
            legalHolidayWorkMinutes: $legalHolidayWorkMinutes,
            prescribedHolidayWorkMinutes: $prescribedHolidayWorkMinutes,
            lateNightPrescribedWorkMinutes: $lateNightPrescribedWorkMinutes,
            lateNightStatutoryWithinOvertimeMinutes: $lateNightStatutoryWithinOvertimeMinutes,
            lateNightStatutoryExcessOvertimeMinutes: $lateNightStatutoryExcessOvertimeMinutes,
            lateNightLegalHolidayWorkMinutes: $lateNightLegalHolidayWorkMinutes,
            reason: $reason,
            adjustedByUserId: $adjustedByUserId,
        ));

        return $this;
    }

    public function delete(string $userId, string $workDate, string $reason, string $deletedByUserId, string $punchLogAction): self
    {
        $this->recordThat(new AttendanceDayDeleted(
            userId: $userId,
            workDate: $workDate,
            reason: $reason,
            deletedByUserId: $deletedByUserId,
            punchLogAction: $punchLogAction,
        ));

        return $this;
    }

    public function syncLiveStatus(
        string $userId,
        string $workDate,
        ?string $shiftAssignmentId,
        string $status,
        string $source,
        ?string $actualStartAt,
        ?int $utcOffsetMinutes,
    ): self {
        $this->recordThat(new AttendanceDayLiveStatusSynced(
            userId: $userId,
            workDate: $workDate,
            shiftAssignmentId: $shiftAssignmentId,
            status: $status,
            source: $source,
            actualStartAt: $actualStartAt,
            utcOffsetMinutes: $utcOffsetMinutes,
        ));

        return $this;
    }

    /**
     * @param  array<int, array{start: string, end: string}>  $breaks
     */
    public function syncFromPunches(
        string $userId,
        string $workDate,
        ?string $shiftAssignmentId,
        string $actualStartAt,
        string $actualEndAt,
        int $utcOffsetMinutes,
        ?string $workLocationType,
        array $breaks,
    ): self {
        $this->recordThat(new AttendanceDaySyncedFromPunches(
            userId: $userId,
            workDate: $workDate,
            shiftAssignmentId: $shiftAssignmentId,
            actualStartAt: $actualStartAt,
            actualEndAt: $actualEndAt,
            utcOffsetMinutes: $utcOffsetMinutes,
            workLocationType: $workLocationType,
            breaks: $breaks,
        ));

        return $this;
    }

    public function autoInsertBreak(string $workStyleId, string $breakStartAt, string $breakEndAt): self
    {
        $this->recordThat(new AttendanceBreakAutoInserted(
            workStyleId: $workStyleId,
            breakStartAt: $breakStartAt,
            breakEndAt: $breakEndAt,
        ));

        return $this;
    }
}
