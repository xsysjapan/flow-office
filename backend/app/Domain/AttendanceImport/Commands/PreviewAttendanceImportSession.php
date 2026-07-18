<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class PreviewAttendanceImportSession implements Command
{
    public function __construct(public readonly int $sessionId) {}
}
