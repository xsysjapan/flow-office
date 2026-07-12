<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 勤務予定(所定労働時間)を編集する。1か月単位変形労働時間制で、特定の日だけ
 * あらかじめ8時間を超える所定労働時間を設定する場合などに使う。
 */
class EditEmployeeShiftAssignment implements Command
{
    public function __construct(
        public readonly int $employeeShiftAssignmentId,
        public readonly ?string $plannedStartAt,
        public readonly ?string $plannedEndAt,
        public readonly int $plannedBreakMinutes,
        public readonly string $reason,
        public readonly int $editedByUserId,
    ) {}
}
