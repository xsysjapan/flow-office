<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class CreateAttendanceImportSession implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $targetMonth,
        public readonly ?string $sourceType,
        public readonly ?string $sourceFileName,
        public readonly ?string $sourceFileHash,
        public readonly ?string $clientType,
        public readonly ?int $integrationId,
    ) {}
}
