<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendarYear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-C009 手順4: 既存年度を複製して翌年度を作成する。
 */
class DuplicateCompanyCalendarYearTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_duplicating_a_year_carries_over_weekday_pattern_but_not_holidays(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        // 平日=WORK・週末=OFFの標準パターンを敷き、さらに祝日・会社休日の上書きを1件加える。
        $days = [];
        $period = Carbon::parse('2026-04-01')->toPeriod('2026-04-30');
        foreach ($period as $date) {
            $isWorkingDay = $date->dayOfWeekIso < 6;
            $days[] = [
                'date' => $date->toDateString(),
                'day_type' => $isWorkingDay ? 'weekday' : 'company_holiday',
                'schedule_state' => $isWorkingDay ? 'WORK' : 'OFF',
            ];
        }
        // 平日なのに臨時休業にした特別な1日(手動上書き、翌年度には引き継がれない)。
        $days[] = ['date' => '2026-04-15', 'day_type' => 'company_holiday', 'schedule_state' => 'OFF', 'is_public_holiday' => true, 'public_holiday_name' => '臨時休業'];
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", ['days' => $days])->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/duplicate");

        $response->assertCreated();
        $response->assertJsonPath('fiscal_year', 2027);
        $response->assertJsonPath('starts_on', '2027-04-01');
        $response->assertJsonPath('ends_on', '2028-03-31');

        $newYearId = $response->json('id');
        // 平日(4/1は水曜)は勤務日として引き継がれる。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $newYearId, 'date' => '2027-04-01 00:00:00', 'schedule_state' => 'WORK',
        ]);
        // 4/15(2027年は木曜)は通常の平日として生成され、祝日上書きは引き継がれない。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $newYearId, 'date' => '2027-04-15 00:00:00', 'schedule_state' => 'WORK', 'is_public_holiday' => false,
        ]);
        // 土曜日は所定休日として引き継がれる(2027-04-03は土曜)。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $newYearId, 'date' => '2027-04-03 00:00:00', 'schedule_state' => 'OFF',
        ]);
    }

    public function test_duplicating_into_an_existing_fiscal_year_fails(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2027, 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31',
        ])->assertCreated();

        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/duplicate")
            ->assertUnprocessable();
    }
}
