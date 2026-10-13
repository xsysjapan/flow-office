<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\StoredEventRow;

/**
 * company_calendar_year.days_updated の集約UUIDの付け替え(論点19 決定1)。
 *
 * マイグレーション 2026_08_10_020000 で年(company_calendar_years)を新設したとき、それ以前の days_updated の集約UUIDは
 * company_calendars.id のまま残っている。集約UUIDが現在の年の id でも、作成イベントのある年の集約でもない days_updated は、
 * 旧集約UUIDが company_calendars.id で、含まれる日付が全てその暦の1つの年の範囲(starts_on〜ends_on)に入るとき、その年の id へ
 * 付け替える(集約UUIDと meta_data の aggregate-root-uuid。版は作り直しで振り直す)。それ以外は扱えない並びとして issues に載せる。
 */
final class CalendarDayAggregateRemapper
{
    public const CLASS_NAME = 'company_calendar_year.days_updated';

    private const YEAR_CREATED = 'company_calendar_year.created';

    /**
     * @param  list<StoredEventRow>  $rows
     * @return array{rows: list<StoredEventRow>, remapped: list<array{id: int, from: string, to: string}>, replaced: list<StoredEventRow>, issues: list<array{key: string, reason: string}>}
     */
    public function remap(array $rows, SeededDataCurrent $current): array
    {
        $yearIds = [];
        $yearsByCalendar = [];
        foreach ($current->calendarYears as $year) {
            $year = Norm::row($year);
            $yearIds[(string) $year['id']] = true;
            $yearsByCalendar[(string) $year['company_calendar_id']][] = [
                'id' => (string) $year['id'],
                'starts_on' => (string) Norm::date($year['starts_on']),
                'ends_on' => (string) Norm::date($year['ends_on']),
            ];
        }
        $calendarIds = array_fill_keys(array_map('strval', $current->companyCalendarIds), true);

        $createdAggregates = [];
        foreach ($rows as $row) {
            if ($row->eventClass === self::YEAR_CREATED && $row->aggregateUuid !== null) {
                $createdAggregates[$row->aggregateUuid] = true;
            }
        }

        $result = [];
        $remapped = [];
        $replaced = [];
        $issues = [];
        foreach ($rows as $row) {
            $aggregate = $row->aggregateUuid;
            if ($row->eventClass !== self::CLASS_NAME || $aggregate === null || isset($yearIds[$aggregate]) || isset($createdAggregates[$aggregate])) {
                $result[] = $row;

                continue;
            }

            $target = $this->targetYear($row, $yearsByCalendar[$aggregate] ?? null, isset($calendarIds[$aggregate]));
            if (is_array($target)) {
                $issues[] = ['key' => $aggregate, 'reason' => $target['reason'].' (days_updated id='.$row->id.')'];
                $result[] = $row;

                continue;
            }

            $meta = $row->meta;
            $meta['aggregate-root-uuid'] = $target;
            $new = new StoredEventRow($row->id, $target, $row->aggregateVersion, $row->eventClass, $row->payload, $meta, $row->createdAt);
            $result[] = $new;
            $replaced[] = $new;
            $remapped[] = ['id' => $row->id, 'from' => $aggregate, 'to' => $target];
        }

        return ['rows' => $result, 'remapped' => $remapped, 'replaced' => $replaced, 'issues' => $issues];
    }

    /**
     * @param  list<array{id: string, starts_on: string, ends_on: string}>|null  $years  旧集約UUIDを company_calendar_id とする年
     * @return string|array{reason: string} 付け替え先の年のid。決められなければ理由
     */
    private function targetYear(StoredEventRow $row, ?array $years, bool $isCalendar): string|array
    {
        if (! $isCalendar || $years === null) {
            return ['reason' => '集約UUIDが現在の年のidでも company_calendars.id でもなく、作成イベントのある年の集約でもない'];
        }
        $dates = [];
        foreach ((array) $row->get('days', []) as $day) {
            if (isset($day['date'])) {
                $dates[] = substr((string) $day['date'], 0, 10);
            }
        }
        if ($dates === []) {
            return ['reason' => '付け替え先の年を決める日付が無い'];
        }

        $targets = [];
        foreach ($dates as $date) {
            $matching = array_values(array_filter($years, fn (array $y) => $y['starts_on'] <= $date && $date <= $y['ends_on']));
            if (count($matching) !== 1) {
                return ['reason' => "日付 {$date} が暦の年の範囲にちょうど1つは入らない(".count($matching).' 件)'];
            }
            $targets[$matching[0]['id']] = true;
        }
        if (count($targets) !== 1) {
            return ['reason' => '日付が複数の年にまたがる'];
        }

        return (string) array_key_first($targets);
    }
}
