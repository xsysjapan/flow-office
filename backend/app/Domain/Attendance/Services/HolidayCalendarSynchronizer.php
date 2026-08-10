<?php

namespace App\Domain\Attendance\Services;

use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarYear;
use App\Models\HolidayCalendarEvent;
use App\Models\HolidayCalendarSource;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sabre\VObject\Reader;

/**
 * UC-C012: 祝日iCalendarソースを同期する。
 *
 * `ics_url`からICS(iCalendar)を取得・パースし、既存の`holiday_calendar_events`との差分
 * (追加・更新・削除)、およびそれに伴う`company_calendar_days`側の変更計画を計算する。
 * 実際の永続化(holiday_calendar_events/company_calendar_days/company_calendar_day_sources
 * への書き込み)は呼び出し元HandlerがHolidayCalendarSourceAggregateのイベントを介して行う
 * (このクラス自身はDBに書き込まない、純粋な計算のみを担う)。
 *
 * VEVENTのUID・DTSTART・SUMMARYのみを読み取る。終日イベントを基本とし、RRULE(繰り返し
 * ルール)を持つVEVENTは展開せず無視してログに警告を出す(対象外。日本の祝日iCalendarフィード
 * は通常年ごとに単発VEVENTを配信するため実用上問題ない)。
 */
class HolidayCalendarSynchronizer
{
    /**
     * @return array{
     *     event_changes: list<array{ics_uid: string, date: string, name: string, action: string}>,
     *     day_changes: list<array{company_calendar_day_id: int, date: string, is_public_holiday: bool, public_holiday_name: ?string}>,
     *     protected_conflicts: list<array{company_calendar_day_id: int, date: string}>,
     * }
     *
     * @throws RuntimeException 取得・パースに失敗した場合
     */
    public function synchronize(HolidayCalendarSource $source): array
    {
        $feedEvents = $this->fetchAndParse($source->ics_url);

        $eventChanges = $this->diffEvents($source, $feedEvents);

        [$dayChanges, $protectedConflicts] = $this->planDayChanges($source, $feedEvents, $eventChanges);

        return [
            'event_changes' => $eventChanges,
            'day_changes' => $dayChanges,
            'protected_conflicts' => $protectedConflicts,
        ];
    }

    /**
     * @return array<string, array{date: string, name: string}> ics_uid => [date, name]
     *
     * @throws RuntimeException
     */
    private function fetchAndParse(string $icsUrl): array
    {
        try {
            $response = Http::get($icsUrl);
        } catch (Exception $e) {
            throw new RuntimeException('祝日iCalendarの取得に失敗しました: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('祝日iCalendarの取得に失敗しました: HTTP '.$response->status());
        }

        try {
            $calendar = Reader::read($response->body());
        } catch (Exception $e) {
            throw new RuntimeException('祝日iCalendarの解析に失敗しました: '.$e->getMessage(), previous: $e);
        }

        $events = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            if (isset($vevent->RRULE)) {
                Log::warning('祝日iCalendar同期: RRULE付きVEVENTは展開せず無視しました。', [
                    'uid' => (string) ($vevent->UID ?? ''),
                ]);

                continue;
            }

            $uid = (string) ($vevent->UID ?? '');
            if ($uid === '' || ! isset($vevent->DTSTART)) {
                continue;
            }

            $date = $vevent->DTSTART->getDateTime()->format('Y-m-d');
            $name = (string) ($vevent->SUMMARY ?? '');

            $events[$uid] = ['date' => $date, 'name' => $name];
        }

        return $events;
    }

    /**
     * @param  array<string, array{date: string, name: string}>  $feedEvents
     * @return list<array{ics_uid: string, date: string, name: string, action: string}>
     */
    private function diffEvents(HolidayCalendarSource $source, array $feedEvents): array
    {
        $existing = HolidayCalendarEvent::query()
            ->where('holiday_calendar_source_id', $source->id)
            ->get()
            ->keyBy('ics_uid');

        $changes = [];

        foreach ($feedEvents as $uid => $event) {
            $current = $existing->get($uid);

            if ($current === null) {
                $changes[] = ['ics_uid' => $uid, 'date' => $event['date'], 'name' => $event['name'], 'action' => 'added'];
            } elseif ($current->date->toDateString() !== $event['date'] || $current->name !== $event['name']) {
                $changes[] = ['ics_uid' => $uid, 'date' => $event['date'], 'name' => $event['name'], 'action' => 'updated'];
            }
        }

        foreach ($existing as $uid => $current) {
            if (! array_key_exists($uid, $feedEvents)) {
                $changes[] = ['ics_uid' => $uid, 'date' => $current->date->toDateString(), 'name' => $current->name, 'action' => 'removed'];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{date: string, name: string}>  $feedEvents
     * @param  list<array{ics_uid: string, date: string, name: string, action: string}>  $eventChanges
     * @return array{0: list<array{company_calendar_day_id: int, date: string, is_public_holiday: bool, public_holiday_name: ?string}>, 1: list<array{company_calendar_day_id: int, date: string}>}
     */
    private function planDayChanges(HolidayCalendarSource $source, array $feedEvents, array $eventChanges): array
    {
        if ($eventChanges === []) {
            return [[], []];
        }

        $affectedDates = array_unique(array_map(fn (array $change) => $change['date'], $eventChanges));

        // このソースを祝日ソースとして利用している全カレンダー年度。
        $years = CompanyCalendarYear::query()
            ->whereHas('companyCalendar', fn ($q) => $q->where('holiday_calendar_source_id', $source->id))
            ->get();

        if ($years->isEmpty()) {
            return [[], []];
        }

        // 更新後、その日付がまだ祝日として存在するか(=フィード上に残っているか)。
        $activeEventByDate = [];
        foreach ($feedEvents as $event) {
            $activeEventByDate[$event['date']] = $event['name'];
        }

        $dayChanges = [];
        $protectedConflicts = [];

        foreach ($years as $year) {
            foreach ($affectedDates as $date) {
                if ($date < $year->starts_on->toDateString() || $date > $year->ends_on->toDateString()) {
                    continue;
                }

                $day = CompanyCalendarDay::query()
                    ->where('calendar_id', $year->id)
                    ->whereDate('date', $date)
                    ->first();

                if ($day === null) {
                    continue;
                }

                $latestSource = $day->sources()->orderByDesc('applied_at')->orderByDesc('id')->first();
                if ($latestSource !== null && $latestSource->source_type === 'manual') {
                    $protectedConflicts[] = ['company_calendar_day_id' => $day->id, 'date' => $date];

                    continue;
                }

                $isPublicHoliday = array_key_exists($date, $activeEventByDate);
                $dayChanges[] = [
                    'company_calendar_day_id' => $day->id,
                    'date' => $date,
                    'is_public_holiday' => $isPublicHoliday,
                    'public_holiday_name' => $isPublicHoliday ? $activeEventByDate[$date] : null,
                ];
            }
        }

        return [$dayChanges, $protectedConflicts];
    }
}
