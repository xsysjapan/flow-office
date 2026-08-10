<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendarYear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
