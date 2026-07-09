<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A005: 日次勤怠を編集する。締め前(ロック前)のみ可能。
 */
class EditAttendanceDay implements Command
{
    /**
     * @param  array<int, array{start: string, end: string}>  $breaks
     */
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly array $breaks,
        public readonly ?string $workType,
        public readonly ?string $note,
        public readonly string $reason,
        public readonly int $editedByUserId,
    ) {}
}
