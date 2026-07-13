<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class WarnUnsubmittedAttendance implements Command
{
    public function __construct(public readonly ?string $asOf = null) {}
}
