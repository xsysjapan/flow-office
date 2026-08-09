<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 出勤日(attendance_days)の新規作成。打刻(attendance_punches)とは勤務日が同じという
 * だけの緩い関係しかなく、打刻の有無にかかわらず作成・削除できる。その月が編集不可
 * (提出済み・承認済み・締め済み)になるまでは、いつでも作成・削除できる(AttendanceEditGuard参照)。
 */
class CreateAttendanceDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_can_be_created_for_an_arbitrary_past_date_with_actual_times(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T18:00:00+09:00',
            'breaks' => [['start' => '2026-06-01T12:00:00+09:00', 'end' => '2026-06-01T13:00:00+09:00']],
            'reason' => '打刻漏れの過去日をまとめて入力',
        ]);

        $response->assertCreated();
        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-06-01')->firstOrFail();
        $this->assertSame(480, $day->calculation->work_minutes);
    }

    public function test_a_day_can_be_created_without_any_actual_times(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'reason' => '打刻を伴わない出勤日の作成',
        ]);

        $response->assertCreated();
        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-06-01')->firstOrFail();
        $this->assertNull($day->actual_start_at);
        $this->assertSame(0, $day->calculation->work_minutes);
    }

    public function test_actual_start_at_more_than_one_day_before_the_work_date_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-10',
            'actual_start_at' => '2026-06-08T22:00:00+09:00',
            'actual_end_at' => '2026-06-10T06:00:00+09:00',
            'reason' => '勤務日から2日離れた出勤時刻(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_actual_start_at_one_day_before_the_work_date_is_allowed(): void
    {
        $employee = User::factory()->create();

        // 夜勤(前日夜〜当日朝)を想定し、勤務日の前日の出勤時刻は許容する。
        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-10',
            'actual_start_at' => '2026-06-09T22:00:00+09:00',
            'actual_end_at' => '2026-06-10T06:00:00+09:00',
            'reason' => '夜勤(前日夜開始)',
        ])->assertCreated();
    }

    public function test_actual_end_at_before_actual_start_at_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-10',
            'actual_start_at' => '2026-06-10T18:00:00+09:00',
            'actual_end_at' => '2026-06-10T09:00:00+09:00',
            'reason' => '退勤が出勤より前(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_a_break_starting_before_actual_start_at_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-10',
            'actual_start_at' => '2026-06-10T09:00:00+09:00',
            'actual_end_at' => '2026-06-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-06-10T08:00:00+09:00', 'end' => '2026-06-10T08:30:00+09:00']],
            'reason' => '出勤前に始まる休憩(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_a_break_ending_after_actual_end_at_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-10',
            'actual_start_at' => '2026-06-10T09:00:00+09:00',
            'actual_end_at' => '2026-06-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-06-10T17:30:00+09:00', 'end' => '2026-06-10T18:30:00+09:00']],
            'reason' => '退勤後まで続く休憩(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_a_break_ending_before_it_starts_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-10',
            'actual_start_at' => '2026-06-10T09:00:00+09:00',
            'actual_end_at' => '2026-06-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-06-10T13:00:00+09:00', 'end' => '2026-06-10T12:00:00+09:00']],
            'reason' => '休憩の終了が開始より前(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_creating_a_day_that_already_exists_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'reason' => '1回目の作成',
        ])->assertCreated();

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'reason' => '2回目の作成(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_creating_a_day_once_the_month_is_approved_is_rejected(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        AttendanceMonth::query()->create([
            'user_id' => $employee->id, 'year_month' => '2026-06', 'status' => 'approved',
            'approver_user_id' => $approver->id,
        ]);

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-15',
            'reason' => '承認済み月への出勤日追加(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_creating_a_day_once_the_month_is_submitted_is_rejected(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        AttendanceMonth::query()->create([
            'user_id' => $employee->id, 'year_month' => '2026-06', 'status' => 'submitted',
            'approver_user_id' => $approver->id,
        ]);

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-15',
            'reason' => '提出済み月への出勤日追加(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_creating_a_day_for_a_returned_month_is_allowed(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        AttendanceMonth::query()->create([
            'user_id' => $employee->id, 'year_month' => '2026-06', 'status' => 'returned',
            'approver_user_id' => $approver->id,
        ]);

        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-15',
            'reason' => '差戻し後の再編集',
        ])->assertCreated();
    }

    public function test_creating_another_users_day_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'reason' => '他人の出勤日を作成しようとするテスト',
        ])->assertForbidden();

        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'reason' => '管理者による代理作成',
        ])->assertCreated();
    }

    public function test_a_created_day_with_actual_times_can_still_be_deleted(): void
    {
        $employee = User::factory()->create();

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-06-01',
            'actual_start_at' => '2026-06-01T09:00:00+09:00',
            'actual_end_at' => '2026-06-01T18:00:00+09:00',
            'breaks' => [],
            'reason' => '削除確認用の作成',
        ])->assertCreated()->json('id');

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", [
            'reason' => '打刻済みの出勤日でも削除できることの確認',
        ])->assertOk();

        $this->assertNull(AttendanceDay::query()->find($dayId));
    }
}
