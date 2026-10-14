<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 祝日の同期イベントへの companyCalendarYearId・date の補完結果(論点19 決定8-2)。
 */
final readonly class HolidayCalendarDayRefRebuild
{
    /**
     * @param  list<RebuiltEvent>  $events  payload を書き換えて置き換えたイベント(補った要素のあるものだけ)
     * @param  list<int>  $consumedIds  置き換えた(元の)行のid
     * @param  int  $filled  companyCalendarYearId か date を補った要素の数
     * @param  int  $unresolved  補えなかった要素の数(対応する id の行が無く、companyCalendarYearId か date が欠けたまま)
     * @param  int  $alreadyComplete  既に companyCalendarYearId・date が揃っていた要素の数
     */
    public function __construct(
        public array $events,
        public array $consumedIds,
        public int $filled,
        public int $unresolved,
        public int $alreadyComplete,
    ) {}

    /** @return array{filled: int, unresolved: int, already_complete: int, events: int} */
    public function report(): array
    {
        return [
            'filled' => $this->filled,
            'unresolved' => $this->unresolved,
            'already_complete' => $this->alreadyComplete,
            'events' => count($this->events),
        ];
    }
}
