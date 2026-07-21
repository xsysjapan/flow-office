<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDailyCalculation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 働き方(employee_shift_assignments.work_style_id)が設定されていない日でも勤怠を
 * 記録できる。その際のフォールバック先(月次働き方割当 → システムのデフォルト働き方)を
 * 確認する(docs/08-usecases-calendar-shift.md参照)。
 */
class DefaultWorkStyleFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createWorkStyle(string $code, int $prescribedDailyMinutes): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => $code, 'name' => $code, 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
    }

    /**
     * 出勤・退勤が矛盾なく1日分として組み立てられるよう、退勤打刻の時刻を出勤より
     * 確実に後にずらす(打刻はwork_dateの壁時計時刻を秒単位で保持するため、同一秒内に
     * 連続して打刻すると出勤・退勤が同時刻になり矛盾ありと判定されてしまう)。
     */
    private function clockInThenOut(User $employee): void
    {
        $today = Carbon::today($employee->timezone);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
    }

    public function test_a_day_with_no_shift_assignment_and_no_default_has_zero_prescribed_minutes(): void
    {
        $employee = User::factory()->create();

        $this->clockInThenOut($employee);
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $calculation = AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->firstOrFail();
        $this->assertSame(0, $calculation->prescribed_work_minutes);
    }

    public function test_falls_back_to_the_system_default_work_style_when_nothing_else_is_assigned(): void
    {
        $employee = User::factory()->create();
        $workStyle = $this->createWorkStyle('standard', 480);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        $this->clockInThenOut($employee);
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $calculation = AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->firstOrFail();
        $this->assertSame(480, $calculation->prescribed_work_minutes);
    }

    public function test_the_users_monthly_work_style_assignment_takes_priority_over_the_system_default(): void
    {
        $employee = User::factory()->create();
        $defaultWorkStyle = $this->createWorkStyle('standard', 480);
        $shiftWorkStyle = $this->createWorkStyle('shift', 420);
        SystemSetting::current()->update(['default_work_style_id' => $defaultWorkStyle->id]);

        $currentMonth = now()->format('Y-m');
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $employee->id, 'year_month' => $currentMonth,
            'work_style_id' => $shiftWorkStyle->id, 'assigned_by_user_id' => $employee->id,
        ]);

        $this->clockInThenOut($employee);
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $calculation = AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->firstOrFail();
        $this->assertSame(420, $calculation->prescribed_work_minutes);
    }

    public function test_admin_can_assign_and_change_a_users_monthly_work_style_without_touching_past_months(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create();
        $regular = $this->createWorkStyle('regular', 480);
        $shift = $this->createWorkStyle('shift', 420);

        $this->actingAs($admin)->postJson('/api/user-work-style-monthly-assignments', [
            'user_id' => $employee->id, 'year_month' => '2026-10', 'work_style_id' => $regular->id,
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/user-work-style-monthly-assignments', [
            'user_id' => $employee->id, 'year_month' => '2026-11', 'work_style_id' => $shift->id,
        ])->assertCreated();

        $history = $this->actingAs($admin)
            ->getJson("/api/user-work-style-monthly-assignments?user_id={$employee->id}")
            ->assertOk()->json();

        $this->assertCount(2, $history);
        $this->assertSame('2026-10', $history[0]['year_month']);
        $this->assertSame($regular->id, $history[0]['work_style_id']);
        $this->assertSame('2026-11', $history[1]['year_month']);
        $this->assertSame($shift->id, $history[1]['work_style_id']);
    }

    public function test_removing_the_current_months_assignment_reverts_to_the_company_default(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create();
        $shift = $this->createWorkStyle('shift', 420);
        $currentYearMonth = now()->format('Y-m');

        $created = $this->actingAs($admin)->postJson('/api/user-work-style-monthly-assignments', [
            'user_id' => $employee->id, 'year_month' => $currentYearMonth, 'work_style_id' => $shift->id,
        ])->assertCreated()->json();

        $this->actingAs($admin)->deleteJson("/api/user-work-style-monthly-assignments/{$created['id']}")
            ->assertNoContent();

        $history = $this->actingAs($admin)
            ->getJson("/api/user-work-style-monthly-assignments?user_id={$employee->id}")
            ->assertOk()->json();
        $this->assertCount(0, $history);
    }

    public function test_removing_a_past_months_assignment_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $employee = User::factory()->create();
        $shift = $this->createWorkStyle('shift', 420);
        $pastYearMonth = now()->subMonth()->format('Y-m');

        $created = $this->actingAs($admin)->postJson('/api/user-work-style-monthly-assignments', [
            'user_id' => $employee->id, 'year_month' => $pastYearMonth, 'work_style_id' => $shift->id,
        ])->assertCreated()->json();

        $this->actingAs($admin)->deleteJson("/api/user-work-style-monthly-assignments/{$created['id']}")
            ->assertStatus(422);
    }
}
