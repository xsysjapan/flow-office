<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\GeneratePatternShiftAssignments;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 週次・月次の勤務予定一括入力。曜日ごとの既定値(開始/終了時刻・休憩分)を指定期間へ
 * 展開し(週次)、月次はさらに特定日だけの上書きを重ねて確定できる。
 */
class GeneratePatternShiftAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function makeWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
    }

    /**
     * 平日(月〜金)9:00〜18:00・休憩60分、土日は未設定(休み)の週次パターン。
     */
    private function weekdayPattern(): array
    {
        $weekday = ['start_time' => '09:00', 'end_time' => '18:00', 'break_minutes' => 60];

        return [1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekday, 6 => null, 7 => null];
    }

    public function test_preview_pattern_does_not_persist_anything(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/preview-pattern', [
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'weekly_pattern' => $this->weekdayPattern(),
        ]);

        $response->assertOk();
        $days = $response->json('days');
        $this->assertSame('2026-08-01', $days[0]['date']);
        // 2026-08-01は土曜日のため、週次パターンでは非勤務日として解決される。
        $this->assertFalse($days[0]['is_working_day']);
        $this->assertSame('2026-08-03', $days[2]['date']);
        $this->assertTrue($days[2]['is_working_day']);
        $this->assertSame(0, EmployeeShiftAssignment::query()->count());
    }

    public function test_weekly_pattern_expands_only_on_matching_weekdays(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        // 2026-08-01は土曜日、08-03は月曜日。
        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/generate-pattern', [
            'user_id' => $employee->id,
            'work_style_id' => $workStyle->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'weekly_pattern' => $this->weekdayPattern(),
        ]);

        $response->assertOk();
        $this->assertSame(9, $response->json('generated_count'));
        $this->assertEmpty($response->json('skipped_dates'));

        $saturday = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-01')->firstOrFail();
        $this->assertFalse($saturday->is_working_day);
        $this->assertTrue($saturday->is_company_holiday);
        $this->assertNull($saturday->planned_start_at);

        $monday = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertTrue($monday->is_working_day);
        $this->assertSame('09:00', $monday->planned_start_at->format('H:i'));
        $this->assertSame('18:00', $monday->planned_end_at->format('H:i'));
        $this->assertSame(60, $monday->planned_break_minutes);
        $this->assertFalse($monday->is_manually_overridden);
    }

    public function test_monthly_day_override_marks_only_that_day_as_manually_overridden(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        // 08-03(月)は本来平日出勤だが、祝日代休として休みに上書きする。
        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/generate-pattern', [
            'user_id' => $employee->id,
            'work_style_id' => $workStyle->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'weekly_pattern' => $this->weekdayPattern(),
            'day_overrides' => [
                '2026-08-03' => null,
                '2026-08-02' => ['start_time' => '10:00', 'end_time' => '15:00', 'break_minutes' => 30],
            ],
        ]);

        $response->assertOk();

        $overriddenHoliday = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertFalse($overriddenHoliday->is_working_day);
        $this->assertTrue($overriddenHoliday->is_manually_overridden);

        $overriddenWorkday = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-02')->firstOrFail();
        $this->assertSame('10:00', $overriddenWorkday->planned_start_at->format('H:i'));
        $this->assertSame(30, $overriddenWorkday->planned_break_minutes);
        $this->assertTrue($overriddenWorkday->is_manually_overridden);

        $unaffected = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-04')->firstOrFail();
        $this->assertFalse($unaffected->is_manually_overridden);
    }

    public function test_days_with_actual_attendance_or_manual_overrides_are_skipped_by_default(): void
    {
        $admin = $this->makeAdmin();
        $workStyle = $this->makeWorkStyle();
        $employee = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/employee-shift-assignments/generate-pattern', [
            'user_id' => $employee->id,
            'work_style_id' => $workStyle->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'weekly_pattern' => $this->weekdayPattern(),
        ])->assertOk();

        $monday = EmployeeShiftAssignment::query()
            ->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        AttendanceDay::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-03', 'shift_assignment_id' => $monday->id,
            'status' => AttendanceDayStatus::CLOCKED_OUT, 'source' => 'manual', 'utc_offset_minutes' => 540,
            'actual_start_at' => '2026-08-03 09:00:00', 'actual_end_at' => '2026-08-03 18:00:00',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/employee-shift-assignments/generate-pattern', [
            'user_id' => $employee->id,
            'work_style_id' => $workStyle->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'weekly_pattern' => $this->weekdayPattern(),
            'overwrite_mode' => GeneratePatternShiftAssignments::OVERWRITE_MODE_SKIP_EDITED,
        ]);

        $response->assertOk();
        $this->assertSame(['2026-08-03'], $response->json('skipped_dates'));
    }
}
