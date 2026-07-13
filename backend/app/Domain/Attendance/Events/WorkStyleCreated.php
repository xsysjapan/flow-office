<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * work_style.created (UC-C002: 勤務形態を作成する)。
 */
class WorkStyleCreated implements DomainEvent
{
    /**
     * @param  array<string, mixed>  $attributes  作成時のwork_styles属性一式。
     */
    public function __construct(
        public readonly int $workStyleId,
        public readonly array $attributes,
        public readonly int $createdByUserId,
    ) {}

    public function eventType(): string
    {
        return 'work_style.created';
    }

    public function payload(): array
    {
        return [
            'work_style_id' => $this->workStyleId,
            'attributes' => $this->attributes,
            'created_by_user_id' => $this->createdByUserId,
        ];
    }
}
