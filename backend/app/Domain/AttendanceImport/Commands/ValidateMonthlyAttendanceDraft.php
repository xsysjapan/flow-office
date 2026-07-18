<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ValidateMonthlyAttendanceDraft implements Command
{
    public function __construct(public readonly int $draftId) {}
}
