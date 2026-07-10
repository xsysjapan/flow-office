<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user.synced_from_ms365
 */
class UserSyncedFromMs365 implements DomainEvent
{
    /**
     * @param  array<string, mixed>  $changes  変更後の値 (name/email/department/job_title/employment_status)
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $wasCreated,
        public readonly array $changes,
    ) {}

    public function eventType(): string
    {
        return 'user.synced_from_ms365';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'was_created' => $this->wasCreated,
            'changes' => $this->changes,
        ];
    }
}
