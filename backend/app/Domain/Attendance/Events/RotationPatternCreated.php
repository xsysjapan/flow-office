<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * rotation_pattern.created (指示書 8.4節: 交代制勤務のローテーションパターンを登録する)。
 * 集約ID(rotation_patterns.id)は`aggregateRootUuid()`から取得する。
 */
class RotationPatternCreated extends ShouldBeStored
{
    /**
     * @param  list<array{sequence: int, shift_pattern_id: string}>  $items
     */
    public function __construct(
        public readonly string $workStyleId,
        public readonly string $name,
        public readonly int $cycleLength,
        public readonly array $items,
        public readonly string $createdByUserId,
    ) {}
}
