<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class BackfillAttendanceMonthLockShare implements Command
{
    public function __construct() {}
}
