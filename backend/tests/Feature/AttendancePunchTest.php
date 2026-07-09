<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-A012: 打刻ログ。矛盾があっても記録は成功し、矛盾なく組み立てられた場合のみ
 * 日次勤怠(attendance_days)に反映されることを確認する。
 *
 * 打刻時刻は他の勤怠APIと同様、アプリのタイムゾーン設定(config('app.timezone'))を
 * 前提としたナイーブな日時文字列で送る想定のため、テストでもオフセットは付けない。
 */
class AttendancePunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_consistent_punches_are_synced_to_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_start', '2026-07-09T12:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_end', '2026-07-09T13:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T18:00:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertSame('punch', $day->source);
        $this->assertSame('clocked_out', $day->status);
        $this->assertSame(1, $day->breaks()->count());
        $this->assertNotNull($day->calculation);
        $this->assertSame(480, $day->calculation->actual_work_minutes);
    }

    public function test_overnight_shift_punches_belong_to_the_explicit_work_date(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        // 21:00に出勤し、翌日6:00に退勤する夜勤。どちらもwork_date=2026-07-09に属させる。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T21:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-10T06:00:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertTrue($day->actual_start_at->equalTo(Carbon::parse('2026-07-09T21:00:00')));
        $this->assertTrue($day->actual_end_at->equalTo(Carbon::parse('2026-07-10T06:00:00')));
        $this->assertSame(540, $day->calculation->actual_work_minutes);
    }

    public function test_inconsistent_punches_are_recorded_but_do_not_touch_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        // clock_inが2件(打刻漏れ・重複を想定)なので矛盾あり。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:05:00')->assertSuccessful();

        $this->assertSame(2, AttendancePunch::query()->where('user_id', $employee->id)->count());
        $this->assertNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first());
    }

    public function test_punches_do_not_overwrite_a_day_already_recorded_via_the_live_clock_flow(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today()->toDateString();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $liveDay = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today)->first();
        $liveActualStart = $liveDay->actual_start_at->toIso8601String();

        // 同じ日に対して(矛盾のない)打刻が別途届いても、liveで確定済みの日は上書きしない。
        $this->recordPunch($employee, $today, 'clock_in', "{$today}T21:00:00")->assertSuccessful();
        $this->recordPunch($employee, $today, 'clock_out', "{$today}T23:00:00")->assertSuccessful();

        $liveDay->refresh();
        $this->assertSame('live', $liveDay->source);
        $this->assertSame($liveActualStart, $liveDay->actual_start_at->toIso8601String());
    }

    public function test_punches_do_not_overwrite_a_locked_day_even_if_punch_sourced(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T18:00:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertSame('punch', $day->source);
        $day->locked_at = Carbon::now();
        $day->save();

        // 元の打刻を消し、新たに(それ単体では矛盾のない)打刻を記録しても、
        // 締め後にロック済みの日は上書きしない。
        AttendancePunch::query()->where('user_id', $employee->id)->delete();
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T08:00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T20:00:00')->assertSuccessful();

        $day->refresh();
        $this->assertTrue($day->actual_start_at->equalTo(Carbon::parse('2026-07-09T09:00:00')));
        $this->assertTrue($day->actual_end_at->equalTo(Carbon::parse('2026-07-09T18:00:00')));
    }

    public function test_recording_a_punch_for_another_user_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance-punches', [
            'user_id' => $other->id,
            'work_date' => '2026-07-09',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00',
            'source' => 'ic_card',
        ])->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->postJson('/api/attendance-punches', [
            'user_id' => $other->id,
            'work_date' => '2026-07-09',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00',
            'source' => 'ic_card',
        ])->assertSuccessful();

        $this->assertSame(1, AttendancePunch::query()->where('user_id', $other->id)->count());
    }

    public function test_index_lists_punches_for_the_given_date_range(): void
    {
        $employee = User::factory()->create();

        $this->recordPunch($employee, '2026-07-09', 'clock_in', '2026-07-09T09:00:00')->assertSuccessful();
        $this->recordPunch($employee, '2026-07-10', 'clock_in', '2026-07-10T09:00:00')->assertSuccessful();

        $response = $this->actingAs($employee)->getJson('/api/attendance-punches?from=2026-07-09&to=2026-07-09');

        $response->assertSuccessful();
        $this->assertCount(1, $response->json());
        $this->assertSame('2026-07-09', $response->json()[0]['work_date']);
    }

    private function recordPunch(User $user, string $workDate, string $punchType, string $punchedAt)
    {
        return $this->actingAs($user)->postJson('/api/attendance-punches', [
            'work_date' => $workDate,
            'punch_type' => $punchType,
            'punched_at' => $punchedAt,
            'source' => 'web',
        ]);
    }
}
