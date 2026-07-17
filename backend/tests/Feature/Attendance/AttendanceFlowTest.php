<?php

namespace Tests\Feature\Attendance;

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
        $this->assertSame(780, $calculation['work_minutes']);
        $this->assertSame(300, $calculation['statutory_excess_overtime_minutes']);
        $this->assertSame(60, $calculation['late_night_work_minutes']);
        // 22:00〜23:00の深夜1時間は、法定外残業の時間帯(18:00〜23:00)に完全に含まれる。
        $this->assertSame(60, $calculation['late_night_statutory_excess_overtime_minutes']);
        $this->assertSame(0, $calculation['late_night_prescribed_work_minutes']);
        $this->assertSame(0, $calculation['late_night_statutory_within_overtime_minutes']);
    }

    /**
    * 所定労働時間(6時間)より長く法定8時間以内で働いた場合、深夜時間帯が「法定内残業」の
    * 時間帯に完全に含まれるケース。所定労働・法定外残業の深夜はいずれも発生しない。
     */
    public function test_late_night_overlapping_non_statutory_overtime_is_recorded_separately(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $dateString = $today->toDateString();
        $nextDateString = $today->copy()->addDay()->toDateString();

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'part-time', 'name' => '時短勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 360, 'prescribed_weekly_minutes' => 1800,
            'default_start_time' => '16:00', 'default_end_time' => '22:00',
            'default_break_minutes' => 0, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $dateString, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => $today->copy()->setTime(16, 0), 'planned_end_at' => $today->copy()->setTime(22, 0),
            'planned_break_minutes' => 0,
        ]);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        // 16:00〜24:00(休憩なし)で労働時間8時間。所定(6時間)は超えるが法定8時間は超えない。
        $editResponse = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => "{$dateString}T16:00:00+09:00",
            'actual_end_at' => "{$nextDateString}T00:00:00+09:00",
            'breaks' => [],
            'reason' => 'テスト調整',
        ]);

        $editResponse->assertOk();
        $calculation = $editResponse->json('calculation');
        $this->assertSame(480, $calculation['work_minutes']);
        $this->assertSame(120, $calculation['statutory_within_overtime_minutes'], '所定6時間を超え法定8時間以内の2時間');
        $this->assertSame(0, $calculation['statutory_excess_overtime_minutes']);
        $this->assertSame(120, $calculation['late_night_work_minutes'], '22:00〜24:00の2時間');
        $this->assertSame(0, $calculation['late_night_prescribed_work_minutes'], '所定労働(16:00〜22:00)は深夜帯にかからない');
        $this->assertSame(120, $calculation['late_night_statutory_within_overtime_minutes'], '法定内残業(22:00〜24:00)がそのまま深夜と重なる');
        $this->assertSame(0, $calculation['late_night_statutory_excess_overtime_minutes']);
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
        $this->assertSame(420, $calculation['work_minutes']);
        $this->assertSame(420, $calculation['late_night_work_minutes']);
        // 所定労働時間が無く労働時間420分は日8時間(480分)以内のため、法定外残業は発生しない。
        $this->assertSame(0, $calculation['statutory_excess_overtime_minutes']);
        $this->assertSame(0, $calculation['late_night_statutory_excess_overtime_minutes']);
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

    /**
     * UC-A011: 締め処理は承認者本人だけでなく管理部(admin・hr_staff)全体の作業のため、
     * 「承認待ち」一覧には自分が承認者の提出済み月次に加え、承認者を問わず全社員の
     * 承認済み(締め処理待ち)月次もadmin・hr_staffになら表示される必要がある。
     */
    public function test_months_to_approve_lists_own_submitted_and_all_approved_months_for_admin(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $today = Carbon::today($employee->timezone);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');

        $yearMonth = $today->format('Y-m');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ]);
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->first()->id;

        // 提出直後: 承認者には見えるが、承認者ではないadminにはまだ見えない。
        $this->actingAs($approver)->getJson('/api/attendance/months/to-approve')->assertJsonCount(1);
        $this->actingAs($admin)->getJson('/api/attendance/months/to-approve')->assertJsonCount(0);

        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")->assertOk();

        // 承認後: 承認者(admin・hr_staffではない)の一覧からは消えるが、adminには
        // 自分が承認者かどうかに関わらず締め処理待ちとして表示される。
        $this->actingAs($approver)->getJson('/api/attendance/months/to-approve')->assertJsonCount(0);
        $this->actingAs($admin)->getJson('/api/attendance/months/to-approve')
            ->assertJsonCount(1)
            ->assertJsonPath('0.status', 'approved');
    }

    /**
     * 管理者は自分以外の社員の週次・月次勤怠も`user_id`を指定して参照できる
     * (docs/07-usecases-attendance.md UC-A006/UC-A007を管理者にも拡張)。管理者以外は
     * 他の社員を指定すると403になる。
     */
    public function test_admin_can_view_another_employees_week_and_month(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $today = Carbon::today($other->timezone);

        $this->actingAs($other)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($other)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)->getJson("/api/attendance/week?start_date={$today->toDateString()}&user_id={$other->id}")
            ->assertForbidden();
        $this->actingAs($employee)->getJson("/api/attendance/months/{$today->format('Y-m')}?user_id={$other->id}")
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $weekResponse = $this->actingAs($admin)->getJson("/api/attendance/week?start_date={$today->toDateString()}&user_id={$other->id}");
        $weekResponse->assertSuccessful();
        $this->assertSame([$other->id], array_values(array_unique(array_column($weekResponse->json(), 'user_id'))));

        $monthResponse = $this->actingAs($admin)->getJson("/api/attendance/months/{$today->format('Y-m')}?user_id={$other->id}");
        $monthResponse->assertSuccessful();
        $this->assertSame($other->id, $monthResponse->json('days.0.user_id'));
    }

    /**
     * `/attendance/months/user/{userId}`は管理者向けの月次一覧(月の選択画面用)で、
     * ルートの`role:admin`ミドルウェアにより管理者以外は本人でも403になる。
     */
    public function test_admin_can_list_months_for_another_employee(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $today = Carbon::today($employee->timezone);

        $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $this->actingAs($employee)->postJson('/api/attendance/clock-out');
        $yearMonth = $today->format('Y-m');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $this->actingAs($employee)->getJson("/api/attendance/months/user/{$employee->id}")->assertForbidden();

        $response = $this->actingAs($admin)->getJson("/api/attendance/months/user/{$employee->id}");
        $response->assertSuccessful()->assertJsonCount(1);
        $this->assertSame($yearMonth, $response->json('0.year_month'));
    }
}
