<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class UserTerminationDateSet implements DomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $terminationDate,
        public readonly int $changedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'user.termination_date_set';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'termination_date' => $this->terminationDate,
            'changed_by_user_id' => $this->changedByUserId,
        ];
    }
}