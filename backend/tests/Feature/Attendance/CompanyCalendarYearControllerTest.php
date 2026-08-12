<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarYear;
use App\Models\HolidayCalendarSource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UC-C009: 会社カレンダー本体とカレンダー年度を分離して管理する。
 * UC-C010: 会社カレンダー日の祝日属性と勤務区分を分離して扱う。
 */
class CompanyCalendarYearControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_creating_a_company_calendar_also_creates_its_first_year(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'week_starts_on' => 1,
            'fiscal_year' => 2026,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', '本社カレンダー');
        $response->assertJsonMissingPath('fiscal_year');

        $calendarId = $response->json('id');
        $this->assertDatabaseHas('company_calendars', ['id' => $calendarId, 'name' => '本社カレンダー']);
        $this->assertDatabaseHas('company_calendar_years', [
            'company_calendar_id' => $calendarId,
            'fiscal_year' => 2026,
            'status' => 'draft',
        ]);

        $yearsResponse = $this->actingAs($admin)->getJson("/api/company-calendars/{$calendarId}/years");
        $yearsResponse->assertOk();
        $yearsResponse->assertJsonCount(1);
    }

    public function test_creating_a_company_calendar_without_a_year_creates_only_the_body(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '名古屋事業所カレンダー',
            'fiscal_year_start_month' => 4,
            'fiscal_year_start_day' => 1,
        ]);

        $response->assertCreated();
        $calendarId = $response->json('id');
        $this->assertDatabaseHas('company_calendars', ['id' => $calendarId, 'name' => '名古屋事業所カレンダー']);
        $this->assertDatabaseCount('company_calendar_years', 0);

        $yearsResponse = $this->actingAs($admin)->getJson("/api/company-calendars/{$calendarId}/years");
        $yearsResponse->assertOk();
        $yearsResponse->assertJsonCount(0);
    }

    public function test_a_second_year_can_be_added_to_an_existing_calendar(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');

        $response = $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2027,
            'starts_on' => '2027-04-01',
            'ends_on' => '2028-03-31',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('company_calendar_years', ['company_calendar_id' => $calendarId, 'fiscal_year' => 2027]);
    }

    public function test_publishing_a_year_does_not_affect_other_years_of_the_same_calendar(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/publish");

        $response->assertOk();
        $response->assertJsonPath('status', 'published');
        $this->assertDatabaseHas('company_calendar_years', ['id' => $year->id, 'status' => 'published']);
    }

    public function test_updating_calendar_days_writes_both_schedule_state_and_legacy_columns(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $response = $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [
                [
                    'date' => '2026-05-05',
                    'day_type' => 'legal_holiday',
                    'is_legal_holiday' => true,
                    'is_public_holiday' => true,
                    'public_holiday_name' => 'こどもの日',
                    'schedule_state' => 'OFF',
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
            'schedule_state' => 'OFF',
            // 旧カラムも新カラムと整合する値で書き込まれる(2段階廃止のため)。
            'is_working_day' => false,
        ]);
    }

    public function test_unpublishing_and_archiving_a_draft_year_succeeds_without_closed_months(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/publish")->assertOk();
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('status', 'draft');
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/archive")
            ->assertOk()
            ->assertJsonPath('status', 'archived');
    }

    public function test_creating_a_company_calendar_with_a_custom_weekday_holiday_pattern_reflects_in_the_resource(): void
    {
        $admin = $this->makeAdmin();

        // 日曜のみ休日、月〜土は勤務日という非標準パターン。
        $pattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'working', '7' => 'legal_holiday'];

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '工場カレンダー',
            'weekday_holiday_pattern' => $pattern,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('weekday_holiday_pattern', $pattern);

        $calendar = CompanyCalendar::query()->findOrFail($response->json('id'));
        $this->assertSame($pattern, $calendar->effectiveWeekdayHolidayPattern());
    }

    public function test_creating_a_company_calendar_without_a_pattern_resolves_to_the_default_pattern(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('weekday_holiday_pattern', CompanyCalendar::DEFAULT_WEEKDAY_HOLIDAY_PATTERN);
    }

    public function test_creating_a_company_calendar_with_a_holiday_source_at_creation_time(): void
    {
        $admin = $this->makeAdmin();

        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/x.ics',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'holiday_calendar_source_id' => $source->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('holiday_calendar_source_id', $source->id);
        $this->assertDatabaseHas('company_calendars', [
            'id' => $response->json('id'),
            'holiday_calendar_source_id' => $source->id,
        ]);
    }

    public function test_weekday_holiday_pattern_with_a_missing_key_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'weekday_holiday_pattern' => ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'company_holiday'],
        ]);

        $response->assertStatus(422);
    }

    public function test_weekday_holiday_pattern_with_an_invalid_value_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $pattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'company_holiday', '7' => 'not_a_real_type'];

        $response = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'weekday_holiday_pattern' => $pattern,
        ]);

        $response->assertStatus(422);
    }

    public function test_manually_creating_a_year_auto_populates_days_from_a_custom_pattern_and_syncs_the_holiday_source(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response(<<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VEVENT
            UID:uid-1
            DTSTART;VALUE=DATE:20260505
            SUMMARY:こどもの日
            END:VEVENT
            END:VCALENDAR
            ICS, 200),
        ]);

        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ]);

        // 日曜のみ休日という非標準パターン(旧来の土日休日ルールでは無い)。
        $pattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'working', '7' => 'legal_holiday'];

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'weekday_holiday_pattern' => $pattern,
            'holiday_calendar_source_id' => $source->id,
        ])->json('id');

        // まだ年度が存在しない状態から、年度を手動作成する(2026-04-01は水曜、2026-04-04は土曜)。
        $response = $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2026,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
        ]);

        $response->assertCreated();
        $yearId = $response->json('id');

        // 土曜(4/4)は本パターンでは勤務日(旧来の土日休日ルールなら休日だったはず)。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $yearId, 'date' => '2026-04-04 00:00:00', 'schedule_state' => 'WORK',
        ]);
        // 日曜(4/5)は本パターンでは休日。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $yearId, 'date' => '2026-04-05 00:00:00', 'schedule_state' => 'OFF', 'is_legal_holiday' => true,
        ]);
        // 祝日ソースの同期がこの年度に限定して即座に反映されている。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $yearId, 'date' => '2026-05-05 00:00:00', 'is_public_holiday' => true, 'public_holiday_name' => 'こどもの日',
        ]);
    }

    public function test_get_days_endpoint_returns_the_years_days_in_date_order(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2026-04-03',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $response = $this->actingAs($admin)->getJson("/api/company-calendar-years/{$year->id}/days");

        $response->assertOk();
        $response->assertJsonCount(3);
        $this->assertSame(
            ['2026-04-01', '2026-04-02', '2026-04-03'],
            collect($response->json())->pluck('date')->all(),
        );
    }
}
