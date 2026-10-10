<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 勤怠日の補正(attendance_day.corrected)を記録する。運用の補正コマンド(data-correction)が発行する専用Command。
 *
 * 各項目は勤怠日の現在の正しい状態一式(AttendanceDayCorrectedと同じ意味)。行が無ければ作り、あれば全項目を置き換える。
 * 同じcorrectionIdで既に補正イベントが記録されていれば何もしない(再実行で二重に記録しない)。
 * 利用者向けの認可・締めガードは持たない(運用コマンドからのみ発行する)。
 */
class CorrectAttendanceDay implements Command
{
    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     * @param  array<string, mixed>|null  $dailyCalculation
     * @param  array<string, mixed>|null  $weeklyOvertimeAllocation
     */
    public function __construct(
        public readonly string $attendanceDayId,
        public readonly string $correctionId,
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $calendarEntryId,
        public readonly string $status,
        public readonly string $source,
        public readonly int $utcOffsetMinutes,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly ?string $workType,
        public readonly ?string $workLocationType,
        public readonly ?string $note,
        public readonly ?string $dayClassification,
        public readonly array $breaks,
        public readonly array $leaveSegments,
        public readonly ?array $dailyCalculation,
        public readonly ?array $weeklyOvertimeAllocation,
        public readonly string $reason,
        public readonly string $correctedByUserId,
    ) {}
}
