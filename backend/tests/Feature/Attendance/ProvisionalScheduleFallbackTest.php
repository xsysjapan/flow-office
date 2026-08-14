<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-C014 手順5: バッチ生成を待たない読み取りフォールバック(暫定計算)。
 */
class ProvisionalScheduleFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_provisional_entries_when_no_published_year_covers_the_range(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->getJson(
            "/api/employee-calendar-entries?user_id={$employee->id}&from=2026-07-01&to=2026-07-05"
        );

        $response->assertOk();
        $response->assertJsonPath('provisional', true);
        $days = $response->json('data');
        $this->assertCount(5, $days);
        $this->assertTrue($days[0]['provisional']);
        // 2026-07-01は水曜(勤務日)、2026-07-04は土曜(所定休日)。
        $this->assertTrue($days[0]['is_working_day']);
        $this->assertFalse($days[3]['is_working_day']);
    }

    public function test_merges_published_company_calendar_with_employee_entry_overrides(): void
    {
        $employee = User::factory()->create();
        $calendar = CompanyCalendar::query()->create(['name' => '本社カレンダー', 'week_starts_on' => 1, 'is_default' => true]);
        $year = $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $year->days()->create([
            'date' => '2026-07-04', 'day_type' => 'company_holiday', 'is_working_day' => false,
            'is_legal_holiday' => false, 'is_company_holiday' => true, 'schedule_state' => 'OFF',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => 'Standard', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);
        EmployeeCalendarEntry::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-07-04', 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
            'is_company_holiday' => false, 'schedule_state' => 'WORK', 'planned_break_minutes' => 0,
            'is_published' => true, 'is_manually_overridden' => true,
        ]);

        $response = $this->actingAs($employee)->getJson(
            "/api/employee-calendar-entries?user_id={$employee->id}&from=2026-07-01&to=2026-07-05"
        );

        $response->assertOk();
        $response->assertJsonPath('provisional', false);
        $this->assertCount(5, $response->json('data'));
        $response->assertJsonPath('data.0.schedule_source', 'company_calendar');
        $response->assertJsonPath('data.3.schedule_source', 'employee_calendar_entry');
        $response->assertJsonPath('data.3.is_working_day', true);
    }

    public function test_monthly_attendance_includes_the_effective_schedule(): void
    {
        $employee = User::factory()->create();
        $calendar = CompanyCalendar::query()->create(['name' => 'Head office', 'week_starts_on' => 1, 'is_default' => true]);
        $year = $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $year->days()->create([
            'date' => '2026-07-05', 'day_type' => 'legal_holiday', 'is_working_day' => false,
            'is_legal_holiday' => true, 'is_company_holiday' => false, 'schedule_state' => 'OFF',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => 'Standard', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        $response = $this->actingAs($employee)->getJson('/api/attendance/months/2026-07');

        $response->assertOk();
        $this->assertCount(31, $response->json('schedule'));
        $holiday = collect($response->json('schedule'))->firstWhere('work_date', '2026-07-05');
        $this->assertTrue($holiday['is_legal_holiday']);
        $this->assertSame('company_calendar', $holiday['schedule_source']);
    }

    public function test_public_holiday_is_exposed_as_a_prescribed_holiday_even_for_legacy_working_rows(): void
    {
        $employee = User::factory()->create();
        $calendar = CompanyCalendar::query()->create(['name' => 'Head office', 'week_starts_on' => 1, 'is_default' => true]);
        $year = $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $year->days()->create([
            'date' => '2026-08-11', 'day_type' => 'weekday', 'is_working_day' => true,
            'is_legal_holiday' => false, 'is_company_holiday' => false, 'is_public_holiday' => true,
            'public_holiday_name' => 'Mountain Day', 'schedule_state' => 'WORK',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => 'Standard', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        $response = $this->actingAs($employee)->getJson('/api/attendance/months/2026-08');

        $response->assertOk();
        $holiday = collect($response->json('schedule'))->firstWhere('work_date', '2026-08-11');
        $this->assertFalse($holiday['is_working_day']);
        $this->assertFalse($holiday['is_legal_holiday']);
        $this->assertTrue($holiday['is_company_holiday']);
        $this->assertSame('OFF', $holiday['schedule_state']);
        $this->assertSame('Mountain Day', $holiday['public_holiday_name']);
    }
}
