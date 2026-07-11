<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能で、
 * 承認済み・締め済みの日次勤怠は削除できない(修正申請ワークフローを使う)。
 */
class DeleteAttendanceDay implements Command
{
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly string $reason,
        public readonly int $deletedByUserId,
    ) {}
}
