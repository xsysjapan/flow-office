<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 祝日の同期イベント(holiday_calendar_source.synced の dayChanges、holiday_calendar_source.sync_reverted の dayReverts)の
 * 各要素に companyCalendarYearId・date(Y-m-d)を補う(論点19 決定8-2)。
 *
 * 要素は company_calendar_day_id(自動採番)で日を指していて、replay で採番が変わると別の日を指す・見つからなくなる。
 * 作り直し前の company_calendar_days の id => (年の id・日付)の対応から補い、Projector が年と日付で日を引けるようにする。
 * - 対応する id の行が無い要素は補わない(そのまま)。
 * - 既にある companyCalendarYearId・date は変えない(欠けている方だけ補う)。
 * - 他のイベント・他のキーは変えない。
 * 純粋な変換(DBには触れない。対応の読み取りは EventStoreRebuildWriter::readCalendarDayIndex)。
 */
final class HolidayCalendarDayRefRebuilder
{
    /** event_class => 要素の配列のキー */
    public const LIST_KEYS = [
        'holiday_calendar_source.synced' => 'dayChanges',
        'holiday_calendar_source.sync_reverted' => 'dayReverts',
    ];

    /**
     * @param  list<StoredEventRow>  $rows
     * @param  array<int, array{yearId: string, date: string}>  $dayIndex  日の id => 年の id・日付
     */
    public function rebuild(array $rows, array $dayIndex): HolidayCalendarDayRefRebuild
    {
        $events = [];
        $consumed = [];
        $filled = 0;
        $unresolved = 0;
        $alreadyComplete = 0;

        foreach ($rows as $row) {
            $listKey = self::LIST_KEYS[$row->eventClass] ?? null;
            if ($listKey === null || ! is_array($row->get($listKey))) {
                continue;
            }

            $changed = false;
            $elements = [];
            foreach ($row->get($listKey) as $element) {
                if (! is_array($element)) {
                    $elements[] = $element;

                    continue;
                }
                $hasYear = ! empty($element['companyCalendarYearId']);
                $hasDate = ! empty($element['date']);
                if ($hasYear && $hasDate) {
                    $alreadyComplete++;
                    $elements[] = $element;

                    continue;
                }

                $current = isset($element['company_calendar_day_id']) ? ($dayIndex[(int) $element['company_calendar_day_id']] ?? null) : null;
                if ($current === null) {
                    $unresolved++;
                    $elements[] = $element;

                    continue;
                }

                if (! $hasYear) {
                    $element['companyCalendarYearId'] = $current['yearId'];
                }
                if (! $hasDate) {
                    $element['date'] = $current['date'];
                }
                $filled++;
                $changed = true;
                $elements[] = $element;
            }

            if (! $changed) {
                continue;
            }

            $payload = $row->payload;
            $payload[$listKey] = $elements;
            $events[] = RebuiltEvent::derived($row, 0, $row->eventClass, (string) $row->aggregateUuid, $payload);
            $consumed[] = $row->id;
        }

        return new HolidayCalendarDayRefRebuild($events, $consumed, $filled, $unresolved, $alreadyComplete);
    }
}
