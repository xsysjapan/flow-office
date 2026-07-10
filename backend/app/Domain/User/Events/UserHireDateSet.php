<?php

namespace App\Domain\User\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * user.hire_date_set
 */
class UserHireDateSet implements DomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $hireDate,
        public readonly int $changedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'user.hire_date_set';
    }

    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'hire_date' => $this->hireDate,
            'changed_by_user_id' => $this->changedByUserId,
        ];
    }
}
