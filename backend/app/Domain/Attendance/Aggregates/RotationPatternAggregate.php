<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\RotationPatternCreated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * rotation_pattern集約。主キー(rotation_patterns.id)はコマンド側/呼び出し元サービスが決めた
 * UUIDで、行の新規作成自体はRotationPatternProjectorに委ねられる。
 * `rotation_pattern_items`は独立した集約ではなく、親と同時にまとめて置き換えられる子行
 * (`work_calendar_days`と同じ扱い)。
 */
class RotationPatternAggregate extends AggregateRoot
{
    /**
     * @param  list<array{sequence: int, shift_pattern_id: string}>  $items
     */
    public function create(
        string $workStyleId,
        string $name,
        array $items,
        string $createdByUserId,
    ): self {
        $this->recordThat(new RotationPatternCreated(
            workStyleId: $workStyleId,
            name: $name,
            cycleLength: count($items),
            items: $items,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }
}
