<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 旧版の付与予定イベントの置き換え結果。
 *
 * @phpstan-type Issue array{key: string, reason: string}
 */
final readonly class PaidLeaveScheduleRebuild
{
    /**
     * @param  list<RebuiltEvent>  $events  導出した現行の形のイベント
     * @param  list<int>  $consumedIds  置き換えた(削除する)旧版の行のid
     * @param  array<string, int>  $replacedByClass  置き換えた旧版のイベントの件数(event_class別)
     * @param  array<string, int>  $derivedByClass  導出したイベントの件数(event_class別)
     * @param  list<array{key: string, reason: string}>  $issues  扱えない並び
     */
    public function __construct(
        public array $events,
        public array $consumedIds,
        public array $replacedByClass,
        public array $derivedByClass,
        public array $issues,
    ) {}
}
