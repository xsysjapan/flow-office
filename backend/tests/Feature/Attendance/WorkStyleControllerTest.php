<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\EmploymentCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-C002: 勤務形態を作成する。UC-C005: シフト制の法定休日ルールをマスタ化する。
 */
class WorkStyleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCalendar(): CompanyCalendar
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return $calendar;
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

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
            'company_calendar_id' => $calendar->id,
            'rounding_unit_minutes' => 15,
            'default_break_start_time' => '12:00',
            'default_break_end_time' => '13:00',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('rounding_unit_minutes', 15);
        $response->assertJsonPath('default_break_start_time', '12:00');
        $response->assertJsonPath('default_break_end_time', '13:00');
    }

    public function test_work_style_time_fields_are_returned_without_seconds_for_editing(): void
    {
        $user = $this->makeAdmin();
        WorkStyle::query()->create([
            'code' => 'fixed-with-seconds',
            'name' => 'Fixed with seconds',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00:00',
            'default_end_time' => '18:00:00',
            'default_break_start_time' => '12:00:00',
            'default_break_end_time' => '13:00:00',
            'core_time_start' => '10:00:00',
            'core_time_end' => '15:00:00',
            'flexible_time_start' => '07:00:00',
            'flexible_time_end' => '22:00:00',
        ]);

        $response = $this->actingAs($user)->getJson('/api/work-styles');

        $response->assertOk();
        $response->assertJsonFragment([
            'code' => 'fixed-with-seconds',
            'default_start_time' => '09:00',
            'default_end_time' => '18:00',
            'default_break_start_time' => '12:00',
            'default_break_end_time' => '13:00',
            'core_time_start' => '10:00',
            'core_time_end' => '15:00',
            'flexible_time_start' => '07:00',
            'flexible_time_end' => '22:00',
        ]);
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
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
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

        $response->assertCreated()->assertJsonPath('company_calendar_id', null);
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
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
        ]);
        $monthlyVariable->assertCreated()->assertJsonPath('work_time_system', 'monthly_variable');

        $managerSupervisor = $this->actingAs($user)->postJson('/api/work-styles', [
            'code' => 'manager-supervisor',
            'name' => '管理監督者',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_MANAGER_SUPERVISOR,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
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
            'company_calendar_id' => $calendar->id,
        ]);

        $response->assertCreated()->assertJsonPath('deemed_daily_minutes', 540);
    }

    public function test_a_work_style_can_be_updated(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $workStyle = WorkStyle::query()->create([
            'code' => 'fixed-standard',
            'name' => '固定時間制',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
        ]);

        $response = $this->actingAs($user)->putJson("/api/work-styles/{$workStyle->id}", [
            'code' => 'fixed-standard',
            'name' => '固定時間制(改)',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 420,
            'prescribed_weekly_minutes' => 2100,
            'company_calendar_id' => $calendar->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('name', '固定時間制(改)')
            ->assertJsonPath('prescribed_daily_minutes', 420)
            ->assertJsonPath('prescribed_weekly_minutes', 2100);

        $this->assertSame('固定時間制(改)', $workStyle->fresh()->name);
    }

    /**
     * 初回オンボーディングで作成された標準の勤務形態(system_generated=true)も、
     * デフォルト指定・システム生成フラグ以外の項目は編集できる。
     */
    public function test_the_system_generated_standard_work_style_can_be_updated(): void
    {
        $user = $this->makeAdmin();

        $standard = WorkStyle::query()->create([
            'code' => 'standard',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00',
            'default_end_time' => '18:00',
            'is_default' => true,
            'system_generated' => true,
        ]);

        $response = $this->actingAs($user)->putJson("/api/work-styles/{$standard->id}", [
            'code' => 'standard',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 420,
            'prescribed_weekly_minutes' => 2100,
            'default_start_time' => '09:30',
            'default_end_time' => '17:30',
        ]);

        $response->assertOk()->assertJsonPath('prescribed_daily_minutes', 420);

        $refreshed = $standard->fresh();
        $this->assertSame(420, $refreshed->prescribed_daily_minutes);
        $this->assertTrue($refreshed->is_default);
        $this->assertTrue($refreshed->system_generated);
    }

    public function test_updating_a_work_style_with_another_styles_code_is_rejected(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        WorkStyle::query()->create([
            'code' => 'taken-code',
            'name' => '既存の勤務形態',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
        ]);

        $workStyle = WorkStyle::query()->create([
            'code' => 'own-code',
            'name' => '編集対象',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
        ]);

        $response = $this->actingAs($user)->putJson("/api/work-styles/{$workStyle->id}", [
            'code' => 'taken-code',
            'name' => '編集対象',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_updating_a_work_style_keeps_its_own_code_valid(): void
    {
        $calendar = $this->makeCalendar();
        $user = $this->makeAdmin();

        $workStyle = WorkStyle::query()->create([
            'code' => 'own-code',
            'name' => '編集対象',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
        ]);

        $response = $this->actingAs($user)->putJson("/api/work-styles/{$workStyle->id}", [
            'code' => 'own-code',
            'name' => '編集対象(改)',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => $calendar->id,
        ]);

        $response->assertOk()->assertJsonPath('name', '編集対象(改)');
    }
}
