<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_punch.recorded
 *
 * AttendancePunchProjectorが行の新規作成自体を担当する。
 */
class AttendancePunchRecorded extends ShouldBeStored
{
    public function __construct(
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $punchType,
        public readonly string $punchedAt,
        public readonly string $source,
        public readonly ?string $note = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $authenticationKeyId = null,
        public readonly ?string $actorUserId = null,
        public readonly bool $offline = false,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $requestId = null,
    ) {}
}
