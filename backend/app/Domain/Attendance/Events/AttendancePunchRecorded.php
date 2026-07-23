<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class AttendancePunchRecorded implements DomainEvent
{
    public function __construct(
        public readonly int $attendancePunchId,
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $punchType,
        public readonly string $punchedAt,
        public readonly string $source,
        public readonly ?string $deviceId = null,
        public readonly ?string $authenticationKeyId = null,
        public readonly ?string $actorUserId = null,
        public readonly bool $offline = false,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $requestId = null,
    ) {}

    public function eventType(): string
    {
        return 'attendance_punch.recorded';
    }

    public function payload(): array
    {
        return [
            'attendance_punch_id' => $this->attendancePunchId,
            'user_id' => $this->userId,
            'work_date' => $this->workDate,
            'punch_type' => $this->punchType,
            'punched_at' => $this->punchedAt,
            'source' => $this->source,
            'device_id' => $this->deviceId,
            'authentication_key_id' => $this->authenticationKeyId,
            'actor_user_id' => $this->actorUserId,
            'offline' => $this->offline,
            'idempotency_key' => $this->idempotencyKey,
            'request_id' => $this->requestId,
        ];
    }
}
