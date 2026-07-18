<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-R002手順3: ユーザーの明示的な指示によってのみ呼び出す(docs/26参照)。
 */
class SubmitMonthlyAttendanceDraft implements Command
{
    public function __construct(
        public readonly int $draftId,
        public readonly int $approverUserId,
        public readonly int $submittedByUserId,
    ) {}
}
