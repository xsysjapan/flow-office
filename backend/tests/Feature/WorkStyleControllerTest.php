<?php

namespace Tests\Feature;

use App\Models\EmploymentCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-C002: 勤務形態を作成する。UC-C005: シフト制の法定休日ルールをマスタ化する。
 */
class WorkStyleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCalendar(): WorkCalendar
    {
        return WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_a_work_style_can_set_a_rounding_unit_and_a_standard_break_window(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'fixed-standard',
            'name' => '固定時間制',
            'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
            'rounding_unit_minutes' => 15,
            'default_break_start_time' => '12:00',
            'default_break_end_time' => '13:00',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('rounding_unit_minutes', 15);
        $response->assertJsonPath('default_break_start_time', '12:00');
        $response->assertJsonPath('default_break_end_time', '13:00');
    }

    public function test_an_invalid_rounding_unit_is_rejected(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'fixed-standard',
            'name' => '固定時間制',
            'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
            'rounding_unit_minutes' => 7,
        ])->assertStatus(422);
    }

    public function test_a_non_shift_based_work_style_defaults_to_the_weekly_legal_holiday_rule(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'fixed-standard',
            'name' => '固定時間制',
            'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
        ]);

        $response->assertCreated()->assertJsonPath('legal_holiday_rule', 'weekly');
    }

    public function test_discretionary_work_time_system_is_accepted(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'discretionary-standard',
            'name' => '裁量労働制',
            'work_time_system' => 'discretionary',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
        ]);

        $response->assertCreated()->assertJsonPath('work_time_system', 'discretionary');
    }

    public function test_an_unknown_work_time_system_is_rejected(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'unknown-system',
            'name' => '不明な制度',
            'work_time_system' => 'something_undefined',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_time_system');
    }

    public function test_the_four_weeks_four_days_rule_requires_a_period_start_date(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'shift-4w4d',
            'name' => 'シフト勤務(変形休日制)',
            'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
            'is_shift_based' => true,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('four_week_period_start_date');
    }

    public function test_the_four_weeks_four_days_rule_is_created_with_its_period_start_date(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'shift-4w4d-ok',
            'name' => 'シフト勤務(変形休日制)',
            'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
            'is_shift_based' => true,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_FOUR_WEEKS_FOUR_DAYS,
            'four_week_period_start_date' => '2026-06-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('legal_holiday_rule', 'four_weeks_four_days')
            ->assertJsonPath('four_week_period_start_date', '2026-06-01');
    }

    public function test_a_work_style_can_be_created_without_a_calendar(): void
    {
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'shift-no-calendar',
            'name' => 'シフト勤務(カレンダーなし)',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'is_shift_based' => true,
        ]);

        $response->assertCreated()->assertJsonPath('calendar_id', null);
    }

    public function test_a_work_style_can_be_associated_with_an_employment_category(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();
        $employmentCategory = EmploymentCategory::query()->create(['code' => 'part_time', 'name' => 'パート']);

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'part-time-shift',
            'name' => 'パート・シフト勤務',
            'employment_category_id' => $employmentCategory->id,
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 360,
            'prescribed_weekly_minutes' => 1800,
            'calendar_id' => $calendar->id,
            'is_shift_based' => true,
        ]);

        $response->assertCreated()->assertJsonPath('employment_category_id', $employmentCategory->id);
    }

    public function test_monthly_variable_and_manager_supervisor_work_time_systems_are_accepted(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $monthlyVariable = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'monthly-variable',
            'name' => '1か月単位変形労働時間制',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_MONTHLY_VARIABLE,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
        ]);
        $monthlyVariable->assertCreated()->assertJsonPath('work_time_system', 'monthly_variable');

        $managerSupervisor = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'manager-supervisor',
            'name' => '管理監督者',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_MANAGER_SUPERVISOR,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'calendar_id' => $calendar->id,
        ]);
        $managerSupervisor->assertCreated()->assertJsonPath('work_time_system', 'manager_supervisor');
    }

    public function test_discretionary_work_style_accepts_a_deemed_daily_minutes_value(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'discretionary-with-deemed',
            'name' => '裁量労働制',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_DISCRETIONARY,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'deemed_daily_minutes' => 540,
            'calendar_id' => $calendar->id,
        ]);

        $response->assertCreated()->assertJsonPath('deemed_daily_minutes', 540);
    }
}
