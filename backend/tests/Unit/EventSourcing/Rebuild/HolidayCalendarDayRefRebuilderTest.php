<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\HolidayCalendarDayRefRebuilder;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use PHPUnit\Framework\TestCase;

/**
 * 祝日の同期イベントへの companyCalendarYearId・date の補完(論点19 決定8-2)の純粋な検証。DBは使わない。
 */
class HolidayCalendarDayRefRebuilderTest extends TestCase
{
    private const SOURCE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const YEAR = '44444444-4444-4444-8444-444444444444';

    private const INDEX = [
        10 => ['yearId' => self::YEAR, 'date' => '2026-05-03'],
        11 => ['yearId' => self::YEAR, 'date' => '2026-05-04'],
    ];

    public function test_synced_day_changes_get_year_id_and_date_and_unmatched_ones_stay_as_they_are(): void
    {
        $row = $this->row(1, 'holiday_calendar_source.synced', 'dayChanges', [
            ['company_calendar_day_id' => 10, 'is_public_holiday' => true, 'public_holiday_name' => '憲法記念日'],
            ['company_calendar_day_id' => 99, 'is_public_holiday' => true, 'public_holiday_name' => '消えた日'],
        ]);
        $result = (new HolidayCalendarDayRefRebuilder)->rebuild([$row], self::INDEX);

        $this->assertSame(1, $result->filled);
        $this->assertSame(1, $result->unresolved);
        $this->assertSame(0, $result->alreadyComplete);
        $this->assertSame([1], $result->consumedIds);
        $this->assertCount(1, $result->events);
        $event = $result->events[0];
        $this->assertSame('holiday_calendar_source.synced', $event->eventClass);
        $this->assertSame(self::SOURCE, $event->aggregateUuid);
        $this->assertSame(1, $event->anchorId);
        $this->assertSame(0, $event->subOrder);
        $this->assertSame(self::YEAR, $event->payload['dayChanges'][0]['companyCalendarYearId']);
        $this->assertSame('2026-05-03', $event->payload['dayChanges'][0]['date']);
        $this->assertSame($row->payload['dayChanges'][1], $event->payload['dayChanges'][1]);
        $this->assertSame($row->payload['eventChanges'], $event->payload['eventChanges']);
        $this->assertSame(['filled' => 1, 'unresolved' => 1, 'already_complete' => 0, 'events' => 1], $result->report());
    }

    public function test_sync_reverted_day_reverts_are_filled_too(): void
    {
        $row = $this->row(2, 'holiday_calendar_source.sync_reverted', 'dayReverts', [
            ['company_calendar_day_id' => 11, 'is_public_holiday' => false, 'public_holiday_name' => null],
        ]);
        $result = (new HolidayCalendarDayRefRebuilder)->rebuild([$row], self::INDEX);

        $this->assertSame(1, $result->filled);
        $this->assertSame(self::YEAR, $result->events[0]->payload['dayReverts'][0]['companyCalendarYearId']);
        $this->assertSame('2026-05-04', $result->events[0]->payload['dayReverts'][0]['date']);
    }

    public function test_existing_date_is_kept_only_the_missing_year_id_is_added_and_complete_elements_are_untouched(): void
    {
        $row = $this->row(1, 'holiday_calendar_source.synced', 'dayChanges', [
            ['company_calendar_day_id' => 10, 'date' => '2026-05-03', 'is_public_holiday' => true],
            ['company_calendar_day_id' => 11, 'date' => '2030-01-01', 'is_public_holiday' => true],
            ['company_calendar_day_id' => 11, 'date' => '2026-05-04', 'companyCalendarYearId' => 'other-year', 'is_public_holiday' => true],
        ]);
        $result = (new HolidayCalendarDayRefRebuilder)->rebuild([$row], self::INDEX);

        $changes = $result->events[0]->payload['dayChanges'];
        $this->assertSame(2, $result->filled);
        $this->assertSame(1, $result->alreadyComplete);
        $this->assertSame('2026-05-03', $changes[0]['date']);
        $this->assertSame(self::YEAR, $changes[0]['companyCalendarYearId']);
        $this->assertSame('2030-01-01', $changes[1]['date']);
        $this->assertSame(self::YEAR, $changes[1]['companyCalendarYearId']);
        $this->assertSame($row->payload['dayChanges'][2], $changes[2]);
    }

    public function test_events_with_nothing_to_fill_and_other_events_are_not_replaced(): void
    {
        $complete = $this->row(1, 'holiday_calendar_source.synced', 'dayChanges', [
            ['company_calendar_day_id' => 10, 'date' => '2026-05-03', 'companyCalendarYearId' => self::YEAR],
        ]);
        $unmatched = $this->row(2, 'holiday_calendar_source.synced', 'dayChanges', [['company_calendar_day_id' => 99]]);
        $other = new StoredEventRow(3, self::SOURCE, 3, 'holiday_calendar_source.updated', ['dayChanges' => [['company_calendar_day_id' => 10]]], [], '2026-09-01 10:00:00');
        $result = (new HolidayCalendarDayRefRebuilder)->rebuild([$complete, $unmatched, $other], self::INDEX);

        $this->assertSame([], $result->events);
        $this->assertSame([], $result->consumedIds);
        $this->assertSame(0, $result->filled);
        $this->assertSame(1, $result->unresolved);
        $this->assertSame(1, $result->alreadyComplete);
    }

    public function test_rebuilder_writes_the_filled_events_in_place_with_renumbered_ids_and_reports_the_counts(): void
    {
        $rows = [
            $this->row(1, 'holiday_calendar_source.synced', 'dayChanges', [['company_calendar_day_id' => 10, 'is_public_holiday' => true]]),
            new StoredEventRow(2, self::SOURCE, 2, 'holiday_calendar_source.updated', ['name' => 'x'], [], '2026-09-01 10:00:00'),
        ];
        $result = (new EventStoreRebuilder)->rebuild($rows, [], [], null, self::INDEX);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(['holiday_calendar_source.synced', 'holiday_calendar_source.updated'], array_column($result->rows, 'event_class'));
        $this->assertSame([1, 2], array_column($result->rows, 'aggregate_version'));
        $this->assertSame(self::YEAR, $result->rows[0]['event_properties']['dayChanges'][0]['companyCalendarYearId']);
        $this->assertSame(['filled' => 1, 'unresolved' => 0, 'already_complete' => 0, 'events' => 1], $result->report['holiday_calendar_day_refs']);
    }

    /** @param  list<array<string, mixed>>  $elements */
    private function row(int $id, string $class, string $listKey, array $elements): StoredEventRow
    {
        return new StoredEventRow($id, self::SOURCE, $id, $class, [
            'eventChanges' => [['ics_uid' => 'u', 'date' => '2026-05-03', 'name' => 'n', 'action' => 'added']],
            $listKey => $elements,
            'syncedByUserId' => null,
        ], ['aggregate-root-uuid' => self::SOURCE], '2026-09-01 10:00:00');
    }
}
