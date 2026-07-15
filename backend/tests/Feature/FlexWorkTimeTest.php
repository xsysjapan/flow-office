<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * フレックスタイム制(work_time_system=flex)。指示書 7章参照。
 * .claude/skills/attendance-calc-review 参照。
 */
class FlexWorkTimeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFlexWorkStyle(array $overrides = []): WorkStyle
    {
        return WorkStyle::query()->create(array_merge([
            'code' => 'flex-'.uniqid(),
            'name' => 'フレックスタイム制',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FLEX,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60,
            'is_shift_based' => false,
            'core_time_enabled' => true,
            'core_time_start' => '10:00',
            'core_time_end' => '15:00',
            'flexible_time_start' => '05:00',
            'flexible_time_end' => '22:00',
        ], $overrides));
    }

    private function assignForMonth(User $user, WorkStyle $workStyle, string $yearMonth): void
    {
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id, 'year_month' => $yearMonth,
            'work_style_id' => $workStyle->id, 'assigned_by_user_id' => $user->id,
        ]);
    }

    private function makeDay(User $user, string $workDate): AttendanceDay
    {
        return AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);
    }

    public function test_attendance_covering_the_full_core_time_is_not_a_violation(): void
    {
        $workStyle = $this->makeFlexWorkStyle();
        $user = User::factory()->create();
        $this->assignForMonth($user, $workStyle, '2026-06');
        $day = $this->makeDay($user, '2026-06-01');

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T18:00:00+09:00',
            'breaks' => [['start' => '2026-06-01T12:00:00+09:00', 'end' => '2026-06-01T13:00:00+09:00']],
            'reason' => 'テストデータ投入',
        ])->assertOk();

        $calculation = $day->refresh()->calculation;
        $this->assertFalse($calculation->core_time_violation);
        $this->assertSame(0, $calculation->statutory_excess_overtime_minutes, 'フレックスは日次残業を判定しない');
        $this->assertSame(0, $calculation->statutory_within_overtime_minutes);
    }

    public function test_leaving_before_core_time_ends_is_a_violation(): void
    {
        $workStyle = $this->makeFlexWorkStyle();
        $user = User::factory()->create();
        $this->assignForMonth($user, $workStyle, '2026-06');
        $day = $this->makeDay($user, '2026-06-01');

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T13:00:00+09:00',
            'breaks' => [],
            'reason' => 'テストデータ投入(コアタイム中に早退)',
        ])->assertOk();

        $this->assertTrue($day->refresh()->calculation->core_time_violation);
    }

    public function test_core_time_violation_is_not_evaluated_when_core_time_is_disabled(): void
    {
        $workStyle = $this->makeFlexWorkStyle(['core_time_enabled' => false]);
        $user = User::factory()->create();
        $this->assignForMonth($user, $workStyle, '2026-06');
        $day = $this->makeDay($user, '2026-06-01');

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T13:00:00+09:00',
            'breaks' => [],
            'reason' => 'テストデータ投入',
        ])->assertOk();

        $this->assertFalse($day->refresh()->calculation->core_time_violation);
    }

    public function test_the_settlement_summary_reports_required_actual_and_remaining_minutes(): void
    {
        $workStyle = $this->makeFlexWorkStyle(['calendar_id' => null]);
        $user = User::factory()->create();
        $this->assignForMonth($user, $workStyle, '2026-06');

        // 2026年6月は月〜金22日(カレンダー未設定のため平日フォールバック)。
        // 1日(月)に8時間だけ労働時間を記録する。
        $day = $this->makeDay($user, '2026-06-01');
        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T18:00:00+09:00',
            'breaks' => [['start' => '2026-06-01T12:00:00+09:00', 'end' => '2026-06-01T13:00:00+09:00']],
            'reason' => 'テストデータ投入',
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-06')->assertOk();
        $summary = $response->json('flex_settlement_summary');

        $this->assertNotNull($summary);
        $this->assertSame(22 * 480, $summary['required_minutes']);
        $this->assertSame(480, $summary['actual_minutes']);
        $this->assertSame(22 * 480 - 480, $summary['remaining_minutes']);
    }

    public function test_the_settlement_summary_is_null_for_non_flex_work_styles(): void
    {
        $workStyle = WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400, 'default_break_minutes' => 60,
        ]);
        $user = User::factory()->create();
        $this->assignForMonth($user, $workStyle, '2026-06');

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-06')->assertOk();

        $this->assertNull($response->json('flex_settlement_summary'));
    }

    public function test_creating_a_flex_work_style_with_valid_settings_succeeds(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/work-styles', [
            'code' => 'flex-ok', 'name' => 'フレックスタイム制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FLEX,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'core_time_enabled' => true, 'core_time_start' => '10:00', 'core_time_end' => '15:00',
            'flexible_time_start' => '05:00', 'flexible_time_end' => '22:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('core_time_start', '10:00')
            ->assertJsonPath('settlement_start_day', 1);
    }

    public function test_core_time_outside_the_flexible_time_window_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/work-styles', [
            'code' => 'flex-bad-core', 'name' => 'フレックスタイム制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FLEX,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'core_time_enabled' => true, 'core_time_start' => '04:00', 'core_time_end' => '15:00',
            'flexible_time_start' => '05:00', 'flexible_time_end' => '22:00',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('core_time_start');
    }

    public function test_core_time_end_before_start_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/work-styles', [
            'code' => 'flex-bad-order', 'name' => 'フレックスタイム制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FLEX,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'core_time_enabled' => true, 'core_time_start' => '15:00', 'core_time_end' => '10:00',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('core_time_end');
    }

    public function test_enabling_core_time_without_a_start_time_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/work-styles', [
            'code' => 'flex-missing-core', 'name' => 'フレックスタイム制', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FLEX,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'core_time_enabled' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('core_time_start');
    }
}
