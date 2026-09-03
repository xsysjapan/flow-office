<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
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

    public function test_marking_a_working_day_as_a_public_holiday_also_makes_it_a_prescribed_holiday(): void
    {
        $admin = $this->makeAdmin();
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => 'Holiday calendar', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->firstOrFail();

        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [[
                'date' => '2026-08-11',
                'day_type' => 'weekday',
                'is_working_day' => true,
                'is_legal_holiday' => false,
                'is_company_holiday' => false,
                'is_public_holiday' => true,
                'public_holiday_name' => 'Mountain Day',
                'schedule_state' => 'WORK',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-08-11 00:00:00',
            'is_public_holiday' => true,
            'day_type' => 'company_holiday',
            'is_working_day' => false,
            'is_company_holiday' => true,
            'schedule_state' => 'OFF',
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

    public function test_deleting_a_year_removes_it_and_its_days_and_allows_recreating_the_same_fiscal_year(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->actingAs($admin)->deleteJson("/api/company-calendar-years/{$year->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('company_calendar_years', ['id' => $year->id]);
        $this->assertDatabaseMissing('company_calendar_days', ['calendar_id' => $year->id]);

        // 同じ年度番号で作り直せる(削除前は一意制約で拒否されていた)。
        $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->assertCreated();
    }

    public function test_deleting_a_year_with_closed_months_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        AttendanceMonth::query()->create([
            'user_id' => $admin->id,
            'year_month' => '2026-04',
            'status' => 'approved',
        ]);

        $this->actingAs($admin)->deleteJson("/api/company-calendar-years/{$year->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('company_calendar_years', ['id' => $year->id]);
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

    public function test_weekday_holiday_pattern_can_be_edited_after_creation_via_update(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
        ])->json('id');

        // 日曜のみ休日という非標準パターンへ後から変更する。
        $pattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'working', '7' => 'legal_holiday'];

        $response = $this->actingAs($admin)->putJson("/api/company-calendars/{$calendarId}", [
            'name' => '本社カレンダー',
            'weekday_holiday_pattern' => $pattern,
        ]);

        $response->assertOk();
        $response->assertJsonPath('weekday_holiday_pattern', $pattern);
        $this->assertSame($pattern, CompanyCalendar::query()->findOrFail($calendarId)->effectiveWeekdayHolidayPattern());
    }

    public function test_allow_daily_holiday_override_defaults_to_true_and_can_be_toggled_on_update(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
        ])->json('id');

        $this->assertDatabaseHas('company_calendars', ['id' => $calendarId, 'allow_daily_holiday_override' => true]);

        $response = $this->actingAs($admin)->putJson("/api/company-calendars/{$calendarId}", [
            'name' => '本社カレンダー',
            'allow_daily_holiday_override' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('allow_daily_holiday_override', false);
        $this->assertDatabaseHas('company_calendars', ['id' => $calendarId, 'allow_daily_holiday_override' => false]);

        // 省略時は現在値を維持する。
        $keepResponse = $this->actingAs($admin)->putJson("/api/company-calendars/{$calendarId}", [
            'name' => '本社カレンダー(改名)',
        ]);
        $keepResponse->assertOk();
        $keepResponse->assertJsonPath('allow_daily_holiday_override', false);
    }

    public function test_saving_days_on_a_locked_calendar_overrides_a_contradicting_classification_with_the_pattern(): void
    {
        $admin = $this->makeAdmin();

        // 月〜金=勤務日、土日=法定休日という(通常より厳しい)パターン固定カレンダー。
        $pattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'legal_holiday', '7' => 'legal_holiday'];

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'weekday_holiday_pattern' => $pattern,
            'allow_daily_holiday_override' => false,
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        // 2026-05-05(火)は本来勤務日だが、クライアントは法定休日として送信する。
        $response = $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [
                [
                    'date' => '2026-05-05',
                    'day_type' => 'legal_holiday',
                    'is_legal_holiday' => true,
                    'is_working_day' => false,
                    'schedule_state' => 'OFF',
                ],
            ],
        ]);

        $response->assertOk();
        // パターン通りの勤務日として保存され、クライアントの申告は無視される。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'day_type' => 'weekday',
            'is_working_day' => true,
            'is_legal_holiday' => false,
            'is_company_holiday' => false,
            'schedule_state' => 'WORK',
        ]);
    }

    public function test_saving_days_on_an_unlocked_calendar_stores_exactly_what_was_submitted(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $this->assertDatabaseHas('company_calendars', ['id' => $calendarId, 'allow_daily_holiday_override' => true]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        // 2026-05-05(火、本来は勤務日)を手動で法定休日として登録する(現行の許容挙動)。
        $response = $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [
                [
                    'date' => '2026-05-05',
                    'day_type' => 'legal_holiday',
                    'is_legal_holiday' => true,
                    'is_working_day' => false,
                    'schedule_state' => 'OFF',
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'day_type' => 'legal_holiday',
            'is_working_day' => false,
            'is_legal_holiday' => true,
            'schedule_state' => 'OFF',
        ]);
    }

    public function test_removing_public_holiday_on_locked_calendar_restores_weekday_holiday_classification(): void
    {
        $admin = $this->makeAdmin();
        $pattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'company_holiday', '7' => 'legal_holiday'];
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => 'Locked calendar', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'weekday_holiday_pattern' => $pattern,
            'allow_daily_holiday_override' => false,
        ])->assertCreated()->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->firstOrFail();

        // 2026-05-03 is Sunday. The submitted working-day flags represent the stale
        // state left after clearing the public-holiday checkbox in the editor.
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [[
                'date' => '2026-05-03', 'day_type' => 'weekday',
                'is_working_day' => true, 'is_legal_holiday' => false,
                'is_company_holiday' => false, 'is_public_holiday' => false,
                'schedule_state' => 'WORK',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-03 00:00:00',
            'day_type' => 'legal_holiday',
            'is_working_day' => false,
            'is_legal_holiday' => true,
            'is_company_holiday' => false,
            'is_public_holiday' => false,
            'schedule_state' => 'OFF',
        ]);
    }

    public function test_regenerating_a_draft_year_resets_manually_edited_days_to_the_current_weekday_pattern(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        // 手動で2026-04-04(土、既定パターンでは所定休日)を勤務日に書き換える。
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [
                ['date' => '2026-04-04', 'day_type' => 'weekday', 'is_working_day' => true, 'schedule_state' => 'WORK'],
            ],
        ])->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id, 'date' => '2026-04-04 00:00:00', 'schedule_state' => 'WORK',
        ]);

        // 年度作成後にカレンダー本体側のパターンを変更する(土曜も法定休日にする)。
        $newPattern = ['1' => 'working', '2' => 'working', '3' => 'working', '4' => 'working', '5' => 'working', '6' => 'legal_holiday', '7' => 'legal_holiday'];
        $this->actingAs($admin)->putJson("/api/company-calendars/{$calendarId}", [
            'name' => '本社カレンダー',
            'weekday_holiday_pattern' => $newPattern,
        ])->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/regenerate");

        $response->assertOk();
        // 手動編集は破棄され、変更後の新しいパターン通りに再生成される。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-04-04 00:00:00',
            'day_type' => 'company_holiday',
            'is_working_day' => false,
            'is_legal_holiday' => true,
            'schedule_state' => 'OFF',
        ]);
    }

    public function test_regenerating_a_year_resyncs_the_assigned_holiday_source_scoped_to_that_year(): void
    {
        $admin = $this->makeAdmin();

        // 作成時点のフィードにはまだ祝日が1件も無い。以降、$icsBodyを差し替えることで
        // 「フィードが更新された」状態を再現する(Http::fakeは同一URLに複数回登録すると
        // 最初に登録したスタブが優先されるため、可変変数を参照キャプチャして切り替える)。
        $icsBody = <<<'ICS'
        BEGIN:VCALENDAR
        VERSION:2.0
        END:VCALENDAR
        ICS;
        Http::fake([
            'https://example.com/holidays-regen.ics' => function () use (&$icsBody) {
                return Http::response($icsBody, 200);
            },
        ]);

        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/holidays-regen.ics',
        ]);

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'holiday_calendar_source_id' => $source->id,
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id, 'date' => '2026-05-05 00:00:00', 'is_public_holiday' => false,
        ]);

        // フィード側に祝日イベントが追加された状態で再生成すると、この年度に限定して
        // 再同期が実行され、新しい祝日が反映される。
        $icsBody = <<<'ICS'
        BEGIN:VCALENDAR
        VERSION:2.0
        BEGIN:VEVENT
        UID:uid-regen-1
        DTSTART;VALUE=DATE:20260505
        SUMMARY:こどもの日
        END:VEVENT
        END:VCALENDAR
        ICS;

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/regenerate");

        $response->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id, 'date' => '2026-05-05 00:00:00', 'is_public_holiday' => true, 'public_holiday_name' => 'こどもの日',
        ]);
    }

    public function test_regenerating_a_published_year_is_rejected_and_leaves_days_untouched(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2026-04-30',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [
                ['date' => '2026-04-04', 'day_type' => 'weekday', 'is_working_day' => true, 'schedule_state' => 'WORK'],
            ],
        ])->assertOk();

        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/publish")->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/regenerate");

        $response->assertStatus(422);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id, 'date' => '2026-04-04 00:00:00', 'schedule_state' => 'WORK',
        ]);
    }

    public function test_correcting_fiscal_year_forcibly_updates_a_published_year_even_with_closed_months(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2025,
            'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/publish")->assertOk();

        // 通常のunpublishなら拒否される締め済み月があっても、年度番号の強制訂正は行える
        // (実績データは日付レンジでのみ紐づき、fiscal_year/calendar_idを直接参照しないため)。
        AttendanceMonth::query()->create([
            'user_id' => $admin->id,
            'year_month' => '2025-04',
            'status' => 'approved',
        ]);
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/unpublish")
            ->assertUnprocessable();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/correct-fiscal-year", [
            'fiscal_year' => 2026,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'reason' => '公開時に年度を誤って2025年度として公開したための訂正',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'published');
        $response->assertJsonPath('fiscal_year', 2026);
        $this->assertDatabaseHas('company_calendar_years', [
            'id' => $year->id,
            'fiscal_year' => 2026,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'status' => 'published',
        ]);

        // 訂正後もこの年度に属する実績月・カレンダー日は一切変更されない
        // (fiscal_year/calendar_idを直接参照するテーブルが無く、日付レンジで扱われるため)。
        $this->assertDatabaseHas('attendance_months', [
            'user_id' => $admin->id,
            'year_month' => '2025-04',
            'status' => 'approved',
        ]);
    }

    public function test_correcting_fiscal_year_to_an_already_used_number_on_the_same_calendar_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2025,
            'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31',
        ])->json('id');
        $firstYear = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->assertCreated();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$firstYear->id}/correct-fiscal-year", [
            'fiscal_year' => 2026,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('company_calendar_years', ['id' => $firstYear->id, 'fiscal_year' => 2025]);
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
