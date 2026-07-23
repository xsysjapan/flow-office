<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.edited
 *
 * UC-A005: 日次勤怠を編集する。AttendanceDayProjectorが行を完全に置き換える
 * (breaks/leaveSegmentsも全件入れ替え)ため、再構築に必要な全フィールドを持たせる。
 * statusはHandlerが編集前の状態(actualEndAtが送られなければ既存statusを維持)を解決した
 * 最終値であり、Projectorはそのまま反映するだけでよい。
 */
class AttendanceDayEdited extends ShouldBeStored
{
    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     */
    public function __construct(
        public readonly int $utcOffsetMinutes,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly string $status,
        public readonly ?string $workType,
        public readonly ?string $workLocationType,
        public readonly bool $workLocationTypeProvided,
        public readonly ?string $note,
        public readonly array $breaks,
        public readonly array $leaveSegments,
        public readonly string $reason,
        public readonly string $editedByUserId,
    ) {}
}
