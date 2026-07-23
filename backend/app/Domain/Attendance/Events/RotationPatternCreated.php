<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * rotation_pattern.created (指示書 8.4節: 交代制勤務のローテーションパターンを登録する)。
 */
class RotationPatternCreated implements DomainEvent
{
    /**
     * @param  list<array{sequence: int, shift_pattern_id: int}>  $items
     */
    public function __construct(
        public readonly int $rotationPatternId,
        public readonly int $workStyleId,
        public readonly string $name,
        public readonly int $cycleLength,
        public readonly array $items,
        public readonly string $createdByUserId,
    ) {}

    public function eventType(): string
    {
        return 'rotation_pattern.created';
    }

    public function payload(): array
    {
        return [
            'rotation_pattern_id' => $this->rotationPatternId,
            'work_style_id' => $this->workStyleId,
            'name' => $this->name,
            'cycle_length' => $this->cycleLength,
            'items' => $this->items,
            'created_by_user_id' => $this->createdByUserId,
        ];
    }
}
