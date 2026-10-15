<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;

/**
 * company_calendar_days の照合(論点19 決定1)。キーは (年の id, 日付)、集約UUIDは親の年の id
 * (days_updated の集約UUIDの付け替えは CalendarDayAggregateRemapper が先に済ませる)。
 *
 * イベントによる最終状態: 年の集約の company_calendar_year.days_updated と company_calendar_year.fiscal_year_corrected を
 * 発生順に畳み込んだもの(Projector CompanyCalendarYearProjector と同じ規則)。
 * - days_updated: その日の行を上書きする(schedule_state・is_working_day を整える)。
 * - fiscal_year_corrected: その年の日を全て落とす(決定8-1。期間の境界は見ない。訂正の後の日は後続の days_updated で作られる)。
 * 比べる列は day_type・is_working_day・is_legal_holiday・is_company_holiday・is_public_holiday・public_holiday_name・
 * schedule_state・note。
 * - 行があり、その日付のイベントが1件も無い: 年の作成イベント(既存または導出)の直後に、行の created_at ごとに
 *   days_updated(その日だけ。updatedByUserId=null)を導出する。作成より前に置くと外部キーで再生できないため、「集約の最初の
 *   イベントの直前」ではなく作成の直後にする。
 * - 行があり、最終状態が違う: 年の集約の最後のイベントの直後に、差のある日だけの days_updated を1件導出する。
 * - 行が無いのにイベントには日がある: days を削除するイベントは無いため導出できない。報告に載せるだけで止めない。
 *   訂正で期間外になった日は fiscal_year_corrected で落ちるため、ここには残らない。
 */
final class CalendarDayReconciler
{
    public const TABLE = 'company_calendar_days';

    private const FISCAL_YEAR_CORRECTED = 'company_calendar_year.fiscal_year_corrected';

    /** 比べる列(fromEvent・fromRow の列)。 */
    private const COLUMNS = [
        'day_type', 'is_working_day', 'is_legal_holiday', 'is_company_holiday', 'is_public_holiday',
        'public_holiday_name', 'schedule_state', 'note',
    ];

    public function reconcile(ReconcileContext $ctx): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $rowsByYear */
        $rowsByYear = [];
        foreach ($ctx->current->calendarDays as $row) {
            $row = Norm::row($row);
            $rowsByYear[(string) $row['calendar_id']][(string) Norm::date($row['date'])] = $row;
        }

        foreach ($ctx->current->calendarYears as $year) {
            $yearId = (string) Norm::row($year)['id'];
            $ctx->guard("company_calendar_days year {$yearId}", fn () => $this->year($ctx, $yearId, $rowsByYear[$yearId] ?? []));
        }
    }

    /** @param  array<string, array<string, mixed>>  $rows  日付 => 行 */
    private function year(ReconcileContext $ctx, string $yearId, array $rows): void
    {
        /** @var array<string, array<string, mixed>> $final */
        $final = [];
        // 集約のイベントを発生順に畳み込む(rowsOfClasses は id 順)。
        foreach ($ctx->rowsOfClasses($yearId, [CalendarDayAggregateRemapper::CLASS_NAME, self::FISCAL_YEAR_CORRECTED]) as $event) {
            if ($event->eventClass === self::FISCAL_YEAR_CORRECTED) {
                $final = [];

                continue;
            }
            foreach ((array) $event->get('days', []) as $day) {
                if (isset($day['date'])) {
                    $final[substr((string) $day['date'], 0, 10)] = $this->fromEvent($day);
                }
            }
        }

        $missing = [];
        $different = [];
        $differenceText = [];
        foreach ($rows as $date => $row) {
            $want = $this->fromRow($row);
            if (! isset($final[$date])) {
                $missing[] = [$date, $want, Norm::str($row['created_at'] ?? null)];
            } elseif ($final[$date] !== $want) {
                $different[] = [$date, $want];
                $differenceText[$date] = ReconcileContext::describe($final[$date], $want, self::COLUMNS);
            }
        }
        foreach (array_diff(array_keys($final), array_keys($rows)) as $date) {
            $ctx->mismatch(self::TABLE, "{$yearId}/{$date}", 'イベントには日があるが行が無い(削除のイベントは無いため導出しない)');
        }

        // イベントが無い日: 行の created_at ごとに、作成イベントの直後へ。
        $byCreatedAt = [];
        foreach ($missing as [$date, $want, $createdAt]) {
            $byCreatedAt[$createdAt ?? ''][] = [$date, $want];
        }
        ksort($byCreatedAt);
        foreach ($byCreatedAt as $createdAt => $days) {
            $ref = $ctx->ref('calendar_year:'.$yearId);
            if ($ref === null) {
                throw new UnsupportedEventSequence("company_calendar_days year {$yearId}", '年の作成イベントが無く、日の置き場所を決められない');
            }
            $createdAt = $createdAt === '' ? null : $createdAt;
            $ctx->emit(self::TABLE, $ctx->afterRef($ref, $createdAt), 'company_calendar_year.days_updated', $yearId, $this->payload($days));
        }

        if ($different !== []) {
            foreach ($different as [$date, $_]) {
                $ctx->mismatch(self::TABLE, "{$yearId}/{$date}", '最終状態が違う: '.$differenceText[$date], [CalendarDayAggregateRemapper::CLASS_NAME]);
            }
            $slot = $ctx->afterLast($yearId);
            if ($slot === null) {
                throw new UnsupportedEventSequence("company_calendar_days year {$yearId}", '年の集約にイベントが無く、最後のイベントの位置を決められない');
            }
            $ctx->emit(self::TABLE, $slot, 'company_calendar_year.days_updated', $yearId, $this->payload($different));
        }
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $days
     * @return array<string, mixed>
     */
    private function payload(array $days): array
    {
        usort($days, fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return [
            'days' => array_map(fn (array $d) => [
                'date' => $d[0],
                'day_type' => $d[1]['day_type'],
                'is_working_day' => $d[1]['is_working_day'],
                'is_legal_holiday' => $d[1]['is_legal_holiday'],
                'is_company_holiday' => $d[1]['is_company_holiday'],
                'is_public_holiday' => $d[1]['is_public_holiday'],
                'public_holiday_name' => $d[1]['public_holiday_name'],
                'schedule_state' => $d[1]['schedule_state'],
                'note' => $d[1]['note'],
            ], $days),
            'updatedByUserId' => null,
        ];
    }

    /**
     * Projector(CompanyCalendarYearProjector::onCompanyCalendarDaysUpdated)と同じ規則で、イベントの日を行の列に直す。
     *
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function fromEvent(array $day): array
    {
        $scheduleState = $day['schedule_state'] ?? null;
        if ($scheduleState !== null) {
            $isWorkingDay = $scheduleState === 'WORK';
        } else {
            $isWorkingDay = (bool) ($day['is_working_day'] ?? true);
            $scheduleState = $isWorkingDay ? 'WORK' : 'OFF';
        }

        return [
            'day_type' => (string) $day['day_type'],
            'is_working_day' => $isWorkingDay,
            'is_legal_holiday' => (bool) ($day['is_legal_holiday'] ?? false),
            'is_company_holiday' => (bool) ($day['is_company_holiday'] ?? false),
            'is_public_holiday' => (bool) ($day['is_public_holiday'] ?? false),
            'public_holiday_name' => Norm::str($day['public_holiday_name'] ?? null),
            'schedule_state' => (string) $scheduleState,
            'note' => Norm::str($day['note'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function fromRow(array $row): array
    {
        return [
            'day_type' => (string) $row['day_type'],
            'is_working_day' => (bool) $row['is_working_day'],
            'is_legal_holiday' => (bool) $row['is_legal_holiday'],
            'is_company_holiday' => (bool) $row['is_company_holiday'],
            'is_public_holiday' => (bool) $row['is_public_holiday'],
            'public_holiday_name' => Norm::str($row['public_holiday_name'] ?? null),
            'schedule_state' => (string) $row['schedule_state'],
            'note' => Norm::str($row['note'] ?? null),
        ];
    }
}
