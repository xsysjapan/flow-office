<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * work_style.default_changed (指示書 3.2節: 会社のデフォルト働き方は常に1件。
 * 新しい働き方をデフォルトに設定した場合は既存のデフォルトを解除する)。
 */
class WorkStyleDefaultChanged implements DomainEvent
{
    public function __construct(
        public readonly int $workStyleId,
        public readonly ?int $previousDefaultWorkStyleId,
        public readonly string $changedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'work_style.default_changed';
    }

    public function payload(): array
    {
        return [
            'work_style_id' => $this->workStyleId,
            'previous_default_work_style_id' => $this->previousDefaultWorkStyleId,
            'changed_by_user_id' => $this->changedByUserId,
        ];
    }
}
