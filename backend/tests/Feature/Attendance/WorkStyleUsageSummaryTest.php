<?php

namespace Tests\Feature\Attendance;

use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\ShiftPattern;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 働き方一覧の管理者向け集計列(指示書 16.1節)。
 */
class WorkStyleUsageSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    public function test_the_default_work_style_counts_employees_without_an_explicit_assignment(): void
    {
        $admin = $this->makeAdmin();
        $defaultStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => false, 'is_default' => true,
        ]);
        $otherStyle = WorkStyle::query()->create([
            'code' => 'flex', 'name' => 'フレックス', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FLEX,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400, 'default_break_minutes' => 60,
        ]);

        // admin自身を含め、active状態のユーザーが3人(admin + employeeA + employeeB)。
        $employeeA = User::factory()->create(['employment_status' => 'active']);
        $employeeB = User::factory()->create(['employment_status' => 'active']);
        $currentYearMonth = now()->format('Y-m');

        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $employeeA->id, 'year_month' => $currentYearMonth,
            'work_style_id' => $otherStyle->id, 'assigned_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/work-styles')->assertOk();
        $styles = collect($response->json());

        $default = $styles->firstWhere('id', $defaultStyle->id);
        $flex = $styles->firstWhere('id', $otherStyle->id);

        // 明示的な割当が無い2人(admin, employeeB)がデフォルトにフォールバックする。
        $this->assertSame(2, $default['applied_employee_count']);
        $this->assertSame(1, $flex['applied_employee_count']);
    }

    public function test_shift_based_work_styles_report_distinct_shift_pattern_usage_and_warn_when_unused(): void
    {
        $admin = $this->makeAdmin();
        $usedStyle = WorkStyle::query()->create([
            'code' => 'rotation', 'name' => '工場3交代制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => true,
        ]);
        $unusedStyle = WorkStyle::query()->create([
            'code' => 'rotation-2', 'name' => '倉庫2交代制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => true,
        ]);
        $patternA = ShiftPattern::query()->create([
            'code' => 'a-shift', 'name' => 'A勤', 'crosses_midnight' => false, 'break_minutes' => 45, 'prescribed_work_minutes' => 435,
        ]);
        $patternB = ShiftPattern::query()->create([
            'code' => 'b-shift', 'name' => 'B勤', 'crosses_midnight' => false, 'break_minutes' => 45, 'prescribed_work_minutes' => 435,
        ]);
        $employee = User::factory()->create();

        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-01', 'work_style_id' => $usedStyle->id,
            'shift_pattern_id' => $patternA->id, 'day_type' => 'a-shift', 'is_working_day' => true,
        ]);
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-02', 'work_style_id' => $usedStyle->id,
            'shift_pattern_id' => $patternB->id, 'day_type' => 'b-shift', 'is_working_day' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/work-styles')->assertOk();
        $styles = collect($response->json());

        $used = $styles->firstWhere('id', $usedStyle->id);
        $unused = $styles->firstWhere('id', $unusedStyle->id);

        $this->assertSame(2, $used['active_shift_pattern_count']);
        $this->assertSame([], $used['configuration_warnings']);
        $this->assertSame(0, $unused['active_shift_pattern_count']);
        $this->assertContains('シフトパターンが割り当てられた勤務予定がまだありません。', $unused['configuration_warnings']);
    }

    public function test_non_shift_based_work_styles_have_a_null_shift_pattern_count(): void
    {
        $admin = $this->makeAdmin();
        $fixedStyle = WorkStyle::query()->create([
            'code' => 'fixed', 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/work-styles')->assertOk();
        $style = collect($response->json())->firstWhere('id', $fixedStyle->id);

        $this->assertNull($style['active_shift_pattern_count']);
        $this->assertSame([], $style['configuration_warnings']);
        $this->assertNotNull($style['updated_at']);
    }
}
