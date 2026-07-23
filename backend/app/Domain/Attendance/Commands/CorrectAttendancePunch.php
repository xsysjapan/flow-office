<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A013: 打刻ログを訂正する。元の打刻行は書き換えず「訂正済み」として残し、
 * 訂正後の値を新しい打刻行として追記する(打刻ログは追記のみ)。
 */
class CorrectAttendancePunch implements Command
{
    public function __construct(
        public readonly string $attendancePunchId,
        public readonly string $punchType,
        public readonly string $punchedAt,
        public readonly string $reason,
        public readonly string $correctedByUserId,
    ) {}
}
