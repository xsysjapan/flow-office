<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * UC-A015: 日次勤怠を削除する。承認前(未提出・提出済み・差戻し)のみ可能で、
 * 承認済み(締めの有無によらない)・締め済みの日次勤怠は削除できない。
 */
class AttendanceDayDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_can_be_deleted_before_the_month_is_submitted(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $response = $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", [
            'reason' => '二重入力の削除',
        ]);
        $response->assertOk();

        $this->assertNull(AttendanceDay::query()->find($dayId));
    }

    public function test_deleting_a_day_can_mark_its_active_punches_as_deleted(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", [
            'reason' => '打刻誤りのため削除',
            'punch_log_action' => 'delete_punches',
        ])->assertOk();

        $this->assertNull(AttendanceDay::query()->find($dayId));
        $this->assertSame(['deleted', 'deleted'], AttendancePunch::query()->pluck('status')->all());
        $this->assertSame(['打刻誤りのため削除', '打刻誤りのため削除'], AttendancePunch::query()->pluck('correction_reason')->all());
    }

    public function test_deleting_a_day_can_recreate_it_from_consistent_punches(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $this->actingAs($employee)->postJson('/api/attendance-punches', [
            'work_date' => $workDate,
            'punch_type' => 'clock_in',
            'punched_at' => "{$workDate}T09:00:00+09:00",
            'source' => 'web',
        ])->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance-punches', [
            'work_date' => $workDate,
            'punch_type' => 'clock_out',
            'punched_at' => "{$workDate}T18:00:00+09:00",
            'source' => 'web',
        ])->assertSuccessful();
        $dayId = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->value('id');

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", [
            'reason' => '打刻内容で再作成',
            'punch_log_action' => 'recreate_from_punches',
        ])->assertOk();

        $recreatedDay = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertNotNull($recreatedDay);
        $this->assertNotSame($dayId, $recreatedDay->id);
        $this->assertSame('punch', $recreatedDay->source);
        $this->assertSame('clocked_out', $recreatedDay->status);
    }

    /**
     * 削除された日次勤怠を参照する過去の `attendance.day_calculated` イベントは
     * `stored_events` に残り続ける(イベントは追記のみ)。`projections:rebuild` で
     * 全件再生した際に、既に存在しない attendance_day_id への外部キー違反で
     * 失敗しないことを確認する(docs/20-implementation-notes.md
     * 「EventStoreを正とし、Projectionは再生成可能にする」)。
     */
    public function test_projections_rebuild_survives_a_deleted_day_that_still_has_calculated_events(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');
        $this->assertNotNull(AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->first());

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", [
            'reason' => '削除済み日のProjection再生成テスト',
        ])->assertOk();

        Artisan::call('projections:rebuild', ['projector' => 'AttendanceDailyCalculationProjector']);

        $this->assertNull(AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->first());
    }

    public function test_deleting_a_day_frees_it_up_so_the_live_clock_flow_can_recreate_it(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '入力し直すため削除'])
            ->assertOk();
        $this->assertNull(AttendanceDay::query()->find($dayId));

        // 同じuser_id・work_dateの組み合わせで再び記録できる(unique制約に抵触しない)。
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        $newDayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');
        $this->assertNotNull($newDayId);
        $this->assertNotSame($dayId, $newDayId);
    }

    /**
     * 既存実装の抜け穴の修正確認: 締め(locked_at)前でも、月次が承認済みになった時点で
     * 日次勤怠の削除・編集はできなくなる。
     */
    public function test_a_day_cannot_be_deleted_or_edited_once_the_month_is_approved_even_before_close(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $yearMonth = now()->format('Y-m');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->first()->id;
        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")->assertOk();

        $dayResponse = $this->actingAs($employee)->getJson("/api/attendance/days/{$dayId}");
        $dayResponse->assertJsonPath('is_locked', false); // 締めはまだ行われていない

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '承認後の削除テスト'])
            ->assertStatus(422);

        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", ['reason' => '承認後の編集テスト'])
            ->assertStatus(422);

        $this->assertNotNull(AttendanceDay::query()->find($dayId));
    }

    public function test_a_day_cannot_be_deleted_once_the_month_is_closed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $yearMonth = now()->format('Y-m');
        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->first()->id;
        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/api/attendance-months/{$monthId}/close")->assertOk();

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '締め後の削除テスト'])
            ->assertStatus(422);
    }

    public function test_a_day_with_consumed_paid_leave_cannot_be_deleted(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $targetDate = '2026-08-10';

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
            'user_id' => $employee->id, 'work_date' => $targetDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$targetDate} 09:00:00", 'planned_end_at' => "{$targetDate} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $targetDate,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $targetDate)->first();
        $this->assertNotNull($day);

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$day->id}", ['reason' => '有給消化済みの日を削除するテスト'])
            ->assertStatus(422);

        $this->assertNotNull(AttendanceDay::query()->find($day->id));
    }

    public function test_deleting_another_users_day_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $this->actingAs($other)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '他人の日次を削除しようとするテスト'])
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $this->actingAs($admin)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '管理者による削除'])
            ->assertOk();
    }
}
