<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ConfirmFieldProvenance implements Command
{
    public function __construct(
        public readonly int $fieldProvenanceId,
        public readonly int $confirmedByUserId,
    ) {}
}
