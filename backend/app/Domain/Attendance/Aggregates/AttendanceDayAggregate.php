<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\AttendanceBreakAutoInserted;
use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayCorrected;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\Attendance\Events\AttendanceWeeklyOvertimeAllocated;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
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
        ?string $calendarEntryId,
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
            calendarEntryId: $calendarEntryId,
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
    public function calculate(array $calculation, ?string $userId = null, ?string $workDate = null): self
    {
        $this->recordThat(new AttendanceDayCalculated(calculation: $calculation, userId: $userId, workDate: $workDate));

        return $this;
    }

    public function adjustCalculation(
        int $prescribedWorkMinutes,
        int $statutoryWithinOvertimeMinutes,
        int $statutoryExcessOvertimeMinutes,
        int $legalHolidayWorkMinutes,
        int $prescribedHolidayWorkMinutes,
        int $payrollWorkMinutes,
        int $lateNightPrescribedWorkMinutes,
        int $lateNightStatutoryWithinOvertimeMinutes,
        int $lateNightStatutoryExcessOvertimeMinutes,
        int $lateNightLegalHolidayWorkMinutes,
        int $lateNightPrescribedHolidayWorkMinutes,
        string $reason,
        string $adjustedByUserId,
        ?string $userId = null,
        ?string $workDate = null,
        ?string $dayClassification = null,
        ?int $workMinutes = null,
    ): self {
        $this->recordThat(new AttendanceDailyCalculationAdjusted(
            prescribedWorkMinutes: $prescribedWorkMinutes,
            statutoryWithinOvertimeMinutes: $statutoryWithinOvertimeMinutes,
            statutoryExcessOvertimeMinutes: $statutoryExcessOvertimeMinutes,
            legalHolidayWorkMinutes: $legalHolidayWorkMinutes,
            prescribedHolidayWorkMinutes: $prescribedHolidayWorkMinutes,
            payrollWorkMinutes: $payrollWorkMinutes,
            lateNightPrescribedWorkMinutes: $lateNightPrescribedWorkMinutes,
            lateNightStatutoryWithinOvertimeMinutes: $lateNightStatutoryWithinOvertimeMinutes,
            lateNightStatutoryExcessOvertimeMinutes: $lateNightStatutoryExcessOvertimeMinutes,
            lateNightLegalHolidayWorkMinutes: $lateNightLegalHolidayWorkMinutes,
            lateNightPrescribedHolidayWorkMinutes: $lateNightPrescribedHolidayWorkMinutes,
            reason: $reason,
            adjustedByUserId: $adjustedByUserId,
            userId: $userId,
            workDate: $workDate,
            dayClassification: $dayClassification,
            workMinutes: $workMinutes,
        ));

        return $this;
    }

    public function allocateWeeklyOvertime(
        string $weekStartDate,
        int $prescribedMinutes,
        int $nonPrescribedMinutes,
        int $lateNightPrescribedMinutes,
        int $lateNightNonPrescribedMinutes,
        string $allocatedByUserId,
    ): self {
        $this->recordThat(new AttendanceWeeklyOvertimeAllocated(
            $weekStartDate,
            $prescribedMinutes,
            $nonPrescribedMinutes,
            $lateNightPrescribedMinutes,
            $lateNightNonPrescribedMinutes,
            $allocatedByUserId,
        ));

        return $this;
    }

    /**
     * 補正専用イベント(attendance_day.corrected)を追記する。勤怠日の現在の正しい状態一式を渡す(論点12・仕様確定事項H)。
     * 同じ補正IDの再実行の抑止はCommandHandlerが行う(このメソッドは常に1件追記する)。
     *
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     * @param  array<string, mixed>|null  $dailyCalculation
     * @param  array<string, mixed>|null  $weeklyOvertimeAllocation
     */
    public function correct(
        string $correctionId,
        string $userId,
        string $workDate,
        ?string $calendarEntryId,
        string $status,
        string $source,
        int $utcOffsetMinutes,
        ?string $actualStartAt,
        ?string $actualEndAt,
        ?string $workType,
        ?string $workLocationType,
        ?string $note,
        ?string $dayClassification,
        array $breaks,
        array $leaveSegments,
        ?array $dailyCalculation,
        ?array $weeklyOvertimeAllocation,
        string $reason,
        string $correctedByUserId,
    ): self {
        if (trim($correctionId) === '' || trim($reason) === '') {
            throw new DomainRuleException('補正IDと補正理由は必須です。');
        }

        $this->recordThat(new AttendanceDayCorrected(
            correctionId: $correctionId,
            userId: $userId,
            workDate: $workDate,
            calendarEntryId: $calendarEntryId,
            status: $status,
            source: $source,
            utcOffsetMinutes: $utcOffsetMinutes,
            actualStartAt: $actualStartAt,
            actualEndAt: $actualEndAt,
            workType: $workType,
            workLocationType: $workLocationType,
            note: $note,
            dayClassification: $dayClassification,
            breaks: $breaks,
            leaveSegments: $leaveSegments,
            dailyCalculation: $dailyCalculation,
            weeklyOvertimeAllocation: $weeklyOvertimeAllocation,
            reason: $reason,
            correctedByUserId: $correctedByUserId,
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
        ?string $calendarEntryId,
        string $status,
        string $source,
        ?string $actualStartAt,
        ?int $utcOffsetMinutes,
    ): self {
        $this->recordThat(new AttendanceDayLiveStatusSynced(
            userId: $userId,
            workDate: $workDate,
            calendarEntryId: $calendarEntryId,
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
        ?string $calendarEntryId,
        string $actualStartAt,
        string $actualEndAt,
        int $utcOffsetMinutes,
        ?string $workLocationType,
        array $breaks,
    ): self {
        $this->recordThat(new AttendanceDaySyncedFromPunches(
            userId: $userId,
            workDate: $workDate,
            calendarEntryId: $calendarEntryId,
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
