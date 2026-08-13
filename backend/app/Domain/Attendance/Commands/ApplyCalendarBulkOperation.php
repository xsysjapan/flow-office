<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class ApplyCalendarBulkOperation implements Command
{
    public const CONFLICT_POLICY_SKIP_EXISTING = 'skip_existing';

    public const CONFLICT_POLICY_OVERWRITE = 'overwrite';

    public const CONFLICT_POLICY_FAIL_ON_CONFLICT = 'fail_on_conflict';

    /**
     * @param  array<string, mixed>  $targetScope
     */
    public function __construct(
        public readonly string $operationType,
        public readonly array $targetScope,
        public readonly string $conflictPolicy,
        public readonly string $reason,
        public readonly string $requestedByUserId,
    ) {}
}
