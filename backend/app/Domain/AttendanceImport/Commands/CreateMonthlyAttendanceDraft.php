<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateMonthlyAttendanceDraft implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $targetMonth,
        public readonly ?string $sourceType,
        public readonly ?string $sourceReference,
        public readonly int $createdByUserId,
    ) {}
}
