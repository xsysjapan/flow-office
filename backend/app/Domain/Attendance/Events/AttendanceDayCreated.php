<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.created
 *
 * 打刻・出勤操作を経由しない、任意の勤務日への出勤日の新規作成。打刻(attendance_punches)と
 * 出勤日(attendance_days)は勤務日が同じというだけの緩い関係しかなく、打刻の有無にかかわらず
 * 作成できる。AttendanceDayProjectorが行(attendance_days / attendance_breaks /
 * attendance_leave_segments)の新規作成自体を担当するため、再構築に必要な全フィールドを持たせる。
 * 日時はutcOffsetMinutesと同じオフセットのISO8601文字列(LocalDateTime::formatWithOffsetMinutes)
 * で保持し、Projector側でLocalDateTime::splitOffsetにより復元する。
 */
class AttendanceDayCreated extends ShouldBeStored
{
    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $shiftAssignmentId,
        public readonly string $status,
        public readonly string $source,
        public readonly int $utcOffsetMinutes,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly ?string $workType,
        public readonly ?string $workLocationType,
        public readonly ?string $note,
        public readonly array $breaks,
        public readonly array $leaveSegments,
        public readonly string $reason,
        public readonly string $createdByUserId,
    ) {}
}
