<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 出勤日(attendance_days)を任意の勤務日に新規作成する。出勤操作(ClockIn)は当日しか
 * 使えないため、過去日・未来日への作成、または打刻を伴わない出勤日の作成にはこちらを使う。
 */
class CreateAttendanceDay implements Command
{
    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{category: string, start: string, end: string, note: string|null}>  $leaveSegments
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $workDate,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly array $breaks,
        public readonly ?string $workType,
        public readonly ?string $note,
        public readonly array $leaveSegments,
        public readonly string $reason,
        public readonly int $createdByUserId,
        public readonly ?string $workLocationType = null,
    ) {}
}
