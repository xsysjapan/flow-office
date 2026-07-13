<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class GenerateRotationShiftAssignments implements Command
{
    public const OVERWRITE_MODE_SKIP_EDITED = 'skip_edited';

    public const OVERWRITE_MODE_OVERWRITE_ALL = 'overwrite_all';

    public function __construct(
        public readonly int $userId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $overwriteMode,
        public readonly int $generatedByUserId,
    ) {}
}
