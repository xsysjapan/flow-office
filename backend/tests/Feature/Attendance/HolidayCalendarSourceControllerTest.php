<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarDaySource;
use App\Models\CompanyCalendarYear;
use App\Models\HolidayCalendarSource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UC-C012: 祝日iCalendarソースを同期する。
 */
class HolidayCalendarSourceControllerTest extends TestCase
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

    public function test_registering_and_syncing_a_source_updates_public_holidays(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-1', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '内閣府祝日カレンダー',
            'ics_url' => 'https://example.com/holidays.ics',
        ])->assertCreated()->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $response->assertOk();
        $response->assertJsonPath('sync_status', 'synced');
        $this->assertDatabaseHas('holiday_calendar_events', ['ics_uid' => 'uid-1', 'name' => 'こどもの日']);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
        ]);
    }

    public function test_fetch_failure_marks_status_failed_and_does_not_touch_calendar_days(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response('', 500),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ]);

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $response->assertOk();
        $response->assertJsonPath('sync_status', 'failed');
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'is_public_holiday' => false,
        ]);
    }

    public function test_manually_overridden_day_is_protected_from_auto_holiday_sync(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-2', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ]);

        $day = CompanyCalendarDay::query()->where('calendar_id', $year->id)->whereDate('date', '2026-05-05')->first();
        CompanyCalendarDaySource::query()->create([
            'id' => (string) Str::uuid(),
            'company_calendar_day_id' => $day->id,
            'source_type' => 'manual',
            'applied_at' => now(),
            'applied_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'is_public_holiday' => false,
        ]);
    }

    public function test_disabling_a_source_prevents_further_batch_sync(): void
    {
        $admin = $this->makeAdmin();
        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/x.ics',
        ]);

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$source->id}/disable")
            ->assertOk()
            ->assertJsonPath('disabled_at', fn ($value) => $value !== null);

        $this->assertTrue($source->refresh()->isDisabled());
    }
}
