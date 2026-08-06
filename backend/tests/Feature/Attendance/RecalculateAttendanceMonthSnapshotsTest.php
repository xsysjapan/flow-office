<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `attendance:recalculate-month-snapshots`は、集計ロジックの追加(平日/所定休日/法定休日別の
 * 出勤日数・内訳)を、既存の提出済み/承認済み/締め済みの月次勤怠のsnapshot_jsonへ反映する。
 * 対象月の日次実績は提出時にロックされ変更されないため、再実行しても結果は変わらない。
 */
class RecalculateAttendanceMonthSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
    }

    private function recordWorkedDay(User $user, WorkStyle $workStyle, string $workDate): AttendanceDay
    {
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
            'is_company_holiday' => false, 'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => "{$workDate}T09:00:00+09:00",
            'actual_end_at' => "{$workDate}T18:30:00+09:00",
            'reason' => 'テストデータ投入',
        ])->assertOk();

        return $day->refresh();
    }

    public function test_recalculates_the_snapshot_of_a_submitted_month(): void
    {
        $user = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->recordWorkedDay($user, $workStyle, '2026-06-01');

        $month = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $user->id,
            'submitted_at' => now(),
            // 新しい集計項目を追加する前に提出されたことを想定した、古い形の(欠けた)snapshot。
            'snapshot_json' => ['work_minutes' => 510],
        ]);

        $this->artisan('attendance:recalculate-month-snapshots')->assertSuccessful();

        $snapshot = $month->fresh()->snapshot_json;
        $this->assertSame(1, $snapshot['work_days_weekday']);
        $this->assertSame(0, $snapshot['work_days_prescribed_holiday']);
        $this->assertSame(0, $snapshot['work_days_legal_holiday']);
        // 9:00〜18:30(休憩記録なし)=570分。所定8時間(480分)を超えた90分が法定外残業になる。
        $this->assertSame(480, $snapshot['weekday_regular_work_minutes']);
        $this->assertSame(90, $snapshot['weekday_statutory_excess_overtime_minutes']);
        $this->assertSame(1, $snapshot['day_count']);
    }

    public function test_dry_run_does_not_change_the_snapshot(): void
    {
        $user = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->recordWorkedDay($user, $workStyle, '2026-06-01');

        $month = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'year_month' => '2026-06',
            'status' => 'submitted',
            'approver_user_id' => $user->id,
            'submitted_at' => now(),
            'snapshot_json' => ['work_minutes' => 510],
        ]);

        $this->artisan('attendance:recalculate-month-snapshots --dry-run')->assertSuccessful();

        $this->assertSame(['work_minutes' => 510], $month->fresh()->snapshot_json);
    }

    public function test_does_not_touch_a_returned_month(): void
    {
        $user = User::factory()->create();

        $month = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'year_month' => '2026-06',
            'status' => 'returned',
            'approver_user_id' => $user->id,
            'submitted_at' => now(),
            'returned_at' => now(),
            'snapshot_json' => ['work_minutes' => 510],
        ]);

        $this->artisan('attendance:recalculate-month-snapshots')->assertSuccessful();

        $this->assertSame(['work_minutes' => 510], $month->fresh()->snapshot_json);
    }

    public function test_year_month_option_scopes_the_target(): void
    {
        $user = User::factory()->create();
        $workStyle = $this->makeWorkStyle();
        $this->recordWorkedDay($user, $workStyle, '2026-06-01');
        $this->recordWorkedDay($user, $workStyle, '2026-07-01');

        $juneMonth = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id, 'year_month' => '2026-06', 'status' => 'submitted',
            'approver_user_id' => $user->id, 'submitted_at' => now(),
            'snapshot_json' => ['work_minutes' => 510],
        ]);
        $julyMonth = AttendanceMonth::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id, 'year_month' => '2026-07', 'status' => 'submitted',
            'approver_user_id' => $user->id, 'submitted_at' => now(),
            'snapshot_json' => ['work_minutes' => 510],
        ]);

        $this->artisan('attendance:recalculate-month-snapshots --year-month=2026-06')->assertSuccessful();

        $this->assertArrayHasKey('work_days_weekday', $juneMonth->fresh()->snapshot_json);
        $this->assertSame(['work_minutes' => 510], $julyMonth->fresh()->snapshot_json);
    }
}
