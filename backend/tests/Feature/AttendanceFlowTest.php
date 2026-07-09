<?php

namespace Tests\Feature;

use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-A001〜UC-A011: 打刻から月次締めまでの一連の流れ。
 */
class AttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_in_break_and_clock_out_calculates_overtime_and_late_night(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $today->toDateString(), 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => $today->copy()->setTime(9, 0), 'planned_end_at' => $today->copy()->setTime(18, 0),
            'planned_break_minutes' => 60,
        ]);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful()->assertJsonPath('status', 'working');
        $this->actingAs($employee)->postJson('/api/attendance/break/start')->assertOk()->assertJsonPath('status', 'on_break');
        $this->actingAs($employee)->postJson('/api/attendance/break/end')->assertOk()->assertJsonPath('status', 'working');

        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        // 社員のタイムゾーン(既定値 Asia/Tokyo)での壁時計時刻を、オフセット付きISO8601で送る
        // (docs/06-usecases-auth.md UC-003: APIの日時は必ずオフセット付きで送受信する)。
        $dateString = $today->toDateString();
        $editResponse = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T23:00:00+09:00",
            'breaks' => [[
                'start' => "{$dateString}T12:00:00+09:00",
                'end' => "{$dateString}T13:00:00+09:00",
            ]],
            'reason' => 'テスト調整',
        ]);

        $editResponse->assertOk();
        $calculation = $editResponse->json('calculation');
        $this->assertSame(780, $calculation['actual_work_minutes']);
        $this->assertSame(300, $calculation['statutory_overtime_minutes']);
        $this->assertSame(60, $calculation['late_night_minutes']);
    }

    /**
     * 海外出張中は、現地時刻(=deep-night判定に使う時刻)が社員本人の既定タイムゾーンと
     * 異なる。編集時に送ったオフセットのまま記録・表示され、深夜時間の判定もその現地時刻を
     * 基準に行われることを確認する (docs/03-architecture.md 3.4)。
     */
    public function test_editing_a_day_with_a_business_trip_offset_preserves_that_offset_and_calculates_late_night_locally(): void
    {
        $employee = User::factory()->create(); // timezone: Asia/Tokyo (既定値)

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        // ニューヨーク出張中(-05:00)、現地22:00〜翌05:00の勤務。
        $editResponse = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-07-09T22:00:00-05:00',
            'actual_end_at' => '2026-07-10T05:00:00-05:00',
            'breaks' => [],
            'reason' => '出張のため現地時刻で記録',
        ]);

        $editResponse->assertOk();
        $editResponse->assertJsonPath('actual_start_at', '2026-07-09T22:00:00-05:00');
        $editResponse->assertJsonPath('actual_end_at', '2026-07-10T05:00:00-05:00');
        $editResponse->assertJsonPath('utc_offset_minutes', -300);

        $calculation = $editResponse->json('calculation');
        $this->assertSame(420, $calculation['actual_work_minutes']);
        $this->assertSame(420, $calculation['late_night_minutes']);
    }

    public function test_editing_a_day_with_mismatched_offsets_across_fields_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $editResponse = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-07-09T22:00:00-05:00',
            'actual_end_at' => '2026-07-10T05:00:00+09:00',
            'breaks' => [],
            'reason' => 'オフセット不一致テスト',
        ]);

        $editResponse->assertStatus(422);
    }

    public function test_month_submit_approve_close_locks_days(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $today = Carbon::today($employee->timezone);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $yearMonth = $today->format('Y-m');

        $submit = $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ]);
        $submit->assertSuccessful()->assertJsonPath('status', 'submitted');
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->first()->id;

        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $this->actingAs($admin)->postJson("/api/attendance-months/{$monthId}/close")
            ->assertOk()->assertJsonPath('status', 'closed');

        $dayResponse = $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}");
        $dayResponse->assertJsonPath('is_locked', true);

        $editAfterClose = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'reason' => '締め後の編集テスト',
        ]);
        $editAfterClose->assertStatus(422);
    }
}
