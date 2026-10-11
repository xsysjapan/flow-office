<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 勤怠日(集約)1件の変換結果。
 *
 * @property-read list<RebuiltEvent> $events 変換後の行(置き換えた行・補った行。変えない行は含めない)
 * @property-read list<int> $consumedIds 変換で置き換えた元の stored_events.id(写さない)
 */
final readonly class AttendanceRebuild
{
    /**
     * @param  list<RebuiltEvent>  $events
     * @param  list<int>  $consumedIds
     */
    public function __construct(
        public array $events,
        public array $consumedIds,
        public bool $createdAdded,
        public bool $deletedAdded,
        public int $workTypeRewritten,
        public int $nullItemsFilled,
    ) {}
}
