<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarDaySource;
use App\Models\CompanyCalendarYear;
use App\Models\HolidayCalendarSource;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 論点19 決定8: 暦(年度の訂正・祝日の同期・一括操作)のProjectorは、再生で同じ結果になる。
 */
class CalendarReplayDeterminismTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function ics(string $uid, string $date, string $summary): string
    {
        return <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        BEGIN:VEVENT
        UID:{$uid}
        DTSTART;VALUE=DATE:{$date}
        SUMMARY:{$summary}
        END:VEVENT
        END:VCALENDAR
        ICS;
    }

    /**
     * Spatieのcreated-atはmeta_dataから読むため、created_at列と両方を固定する。
     *
     * @param  list<string>  $eventClasses
     */
    private function pinEventTime(array $eventClasses, string $at): void
    {
        foreach (DB::table('stored_events')->whereIn('event_class', $eventClasses)->get() as $stored) {
            $meta = json_decode($stored->meta_data, true) ?: [];
            $meta['created-at'] = $at;
            DB::table('stored_events')->where('id', $stored->id)->update([
                'created_at' => $at,
                'meta_data' => json_encode($meta),
            ]);
        }
    }

    private function emptyCalendarReadModels(): void
    {
        DB::table('company_calendar_day_sources')->delete();
        DB::table('holiday_calendar_events')->delete();
        DB::table('company_calendar_days')->delete();
        DB::table('company_calendar_years')->delete();
    }

    /**
     * @return array{0: string, 1: CompanyCalendarYear}
     */
    private function setUpSyncedYear(User $admin, string $fiscalYear = '2026', string $icsDate = '20260505'): array
    {
        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-1', $icsDate, 'こどもの日'), 200),
        ]);
        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '祝日', 'ics_url' => 'https://example.com/holidays.ics',
        ])->assertCreated()->json('id');
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => (int) $fiscalYear,
            'starts_on' => "{$fiscalYear}-04-01", 'ends_on' => ((int) $fiscalYear + 1).'-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->firstOrFail();

        return [$sourceId, $year];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'days' => DB::table('company_calendar_days')->orderBy('date')->get()
                ->map(fn ($d) => [substr($d->date, 0, 10), (bool) $d->is_public_holiday, $d->public_holiday_name, $d->schedule_state])->all(),
            'sources' => DB::table('company_calendar_day_sources')
                ->join('company_calendar_days', 'company_calendar_days.id', '=', 'company_calendar_day_sources.company_calendar_day_id')
                ->orderBy('company_calendar_days.date')
                ->get(['company_calendar_days.date', 'company_calendar_day_sources.source_type'])
                ->map(fn ($s) => [substr($s->date, 0, 10), $s->source_type])->all(),
        ];
    }

    public function test_fiscal_year_correction_removes_out_of_range_days_and_sources_and_replays_the_same(): void
    {
        $admin = $this->makeAdmin();
        // 誤った年度(2025年度)に祝日(2025-05-05)を同期してから、2026年度へ訂正する。
        [$sourceId, $year] = $this->setUpSyncedYear($admin, '2025', '20250505');
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();
        $this->assertDatabaseHas('company_calendar_days', ['calendar_id' => $year->id, 'date' => '2025-05-05 00:00:00', 'is_public_holiday' => true]);
        $this->assertGreaterThan(0, CompanyCalendarDaySource::query()->count());

        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/correct-fiscal-year", [
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->assertOk();

        $this->assertDatabaseMissing('company_calendar_days', ['calendar_id' => $year->id, 'date' => '2025-05-05 00:00:00']);
        $this->assertDatabaseHas('company_calendar_days', ['calendar_id' => $year->id, 'date' => '2026-04-04 00:00:00']);
        // 範囲外の日に紐づく出典は残らない(出典の親の日が必ず存在する)。
        $this->assertSame(0, DB::table('company_calendar_day_sources')
            ->leftJoin('company_calendar_days', 'company_calendar_days.id', '=', 'company_calendar_day_sources.company_calendar_day_id')
            ->whereNull('company_calendar_days.id')->count());
        $this->assertSame(0, CompanyCalendarDay::query()->where('calendar_id', $year->id)->whereDate('date', '<', '2026-04-01')->count());

        $before = $this->snapshot();

        $this->emptyCalendarReadModels();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('company_calendar_days', ['calendar_id' => $year->id, 'date' => '2025-05-05 00:00:00']);
    }

    public function test_day_change_with_date_finds_the_right_day_even_when_day_ids_change_after_replay(): void
    {
        $admin = $this->makeAdmin();
        [$sourceId, $year] = $this->setUpSyncedYear($admin);
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        $stored = DB::table('stored_events')->where('event_class', 'holiday_calendar_source.synced')->first();
        $change = json_decode($stored->event_properties, true)['dayChanges'][0];
        $this->assertSame($year->id, $change['companyCalendarYearId']);
        $this->assertSame('2026-05-05', $change['date']);

        // ReadModelを空にして再生する(日は新しい自動採番idで作り直される)。
        $this->emptyCalendarReadModels();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $replayedDay = CompanyCalendarDay::query()->where('calendar_id', $year->id)->whereDate('date', '2026-05-05')->firstOrFail();
        $this->assertNotSame($change['company_calendar_day_id'], $replayedDay->id);
        $this->assertTrue((bool) $replayedDay->is_public_holiday);
        $this->assertSame('こどもの日', $replayedDay->public_holiday_name);
        $this->assertSame(1, CompanyCalendarDaySource::query()
            ->where('company_calendar_day_id', $replayedDay->id)->where('source_type', 'holiday_sync')->count());
    }

    public function test_legacy_day_change_without_date_is_looked_up_by_day_id(): void
    {
        $admin = $this->makeAdmin();
        [$sourceId, $year] = $this->setUpSyncedYear($admin);
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        // 旧形式(companyCalendarYearIdとdateが無い)に書き換える。
        $stored = DB::table('stored_events')->where('event_class', 'holiday_calendar_source.synced')->first();
        $props = json_decode($stored->event_properties, true);
        foreach ($props['dayChanges'] as &$change) {
            unset($change['companyCalendarYearId'], $change['date']);
        }
        unset($change);
        DB::table('stored_events')->where('id', $stored->id)->update(['event_properties' => json_encode($props)]);

        // 日のidは変わらない(日の行は消さない)ので、idで引ける。
        DB::table('company_calendar_day_sources')->delete();
        DB::table('holiday_calendar_events')->delete();
        CompanyCalendarDay::query()->where('calendar_id', $year->id)->update(['is_public_holiday' => false, 'public_holiday_name' => null]);
        $dayId = CompanyCalendarDay::query()->where('calendar_id', $year->id)->whereDate('date', '2026-05-05')->value('id');
        $this->assertSame($dayId, $props['dayChanges'][0]['company_calendar_day_id']);

        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $day = CompanyCalendarDay::query()->findOrFail($dayId);
        $this->assertTrue((bool) $day->is_public_holiday);
        $this->assertSame(1, CompanyCalendarDaySource::query()->where('company_calendar_day_id', $dayId)->count());
    }

    public function test_holiday_sync_and_disable_timestamps_are_the_event_times_after_replay(): void
    {
        $admin = $this->makeAdmin();
        [$sourceId] = $this->setUpSyncedYear($admin);
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/disable")->assertOk();

        $this->pinEventTime(['holiday_calendar_source.synced'], '2026-03-01 09:00:00');
        $this->pinEventTime(['holiday_calendar_source.disabled'], '2026-03-02 10:00:00');
        $this->emptyCalendarReadModels();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $source = HolidayCalendarSource::query()->findOrFail($sourceId);
        $this->assertSame('2026-03-01 09:00:00', $source->last_synced_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02 10:00:00', $source->disabled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 09:00:00', DB::table('holiday_calendar_events')->value('synced_at'));
        $applied = CompanyCalendarDaySource::query()->where('source_type', 'holiday_sync')->firstOrFail();
        $this->assertSame('2026-03-01 09:00:00', $applied->applied_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_calendar_bulk_operation_timestamps_are_the_event_times_after_replay(): void
    {
        $admin = $this->makeAdmin();
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00', 'default_break_minutes' => 60,
            'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        $employee = User::factory()->create();
        $id = $this->actingAs($admin)->postJson('/api/calendar-bulk-operations', [
            'operation_type' => 'calendar_apply',
            'target_scope' => ['user_ids' => [$employee->id], 'work_style_id' => $workStyle->id, 'from' => '2026-07-01', 'to' => '2026-07-02'],
            'conflict_policy' => 'skip_existing',
            'reason' => '7月分',
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->postJson("/api/calendar-bulk-operations/{$id}/revert")->assertOk();

        $this->pinEventTime(['calendar_bulk_operation.applied'], '2026-03-01 09:00:00');
        $this->pinEventTime(['calendar_bulk_operation.reverted'], '2026-03-03 11:00:00');
        DB::table('calendar_bulk_operation_targets')->delete();
        DB::table('calendar_bulk_operations')->delete();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $operation = DB::table('calendar_bulk_operations')->where('id', $id)->first();
        $this->assertSame('2026-03-01 09:00:00', $operation->applied_at);
        $this->assertSame('2026-03-03 11:00:00', $operation->reverted_at);
    }
}
