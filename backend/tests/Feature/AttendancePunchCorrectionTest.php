<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendancePunch;
use App\Models\Role;
use App\Models\User;
use App\Support\LocalDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-A013: 打刻ログを訂正する / UC-A014: 打刻ログを削除する。
 *
 * 打刻ログは追記のみで、訂正・削除のいずれも既存の行を物理的には書き換えない
 * (訂正済み・削除済みとして残し、理由・実行者・日時付きで参照できる)。
 */
class AttendancePunchCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function recordPunch(User $user, string $workDate, string $punchType, string $punchedAt)
    {
        return $this->actingAs($user)->postJson('/api/attendance-punches', [
            'work_date' => $workDate,
            'punch_type' => $punchType,
            'punched_at' => $punchedAt,
            'source' => 'web',
        ]);
    }

    public function test_correcting_a_punch_marks_the_original_as_corrected_and_appends_a_new_active_punch(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $punchId = $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:30:00+09:00')
            ->assertSuccessful()->json('id');

        // 実際の出勤は09:00だったが、誤って09:30と打刻されていたケース。
        $response = $this->actingAs($employee)->putJson("/api/attendance-punches/{$punchId}", [
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00+09:00',
            'reason' => '打刻時刻の入力ミス',
        ]);
        $response->assertSuccessful();
        $correctedId = $response->json('id');
        $this->assertNotSame($punchId, $correctedId);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('punched_at', '2026-07-09T09:00:00+09:00');

        $original = AttendancePunch::query()->find($punchId);
        $this->assertSame('corrected', $original->status);
        $this->assertSame('打刻時刻の入力ミス', $original->correction_reason);
        $this->assertSame($employee->id, $original->corrected_by_user_id);
        $this->assertNotNull($original->corrected_at);
        $this->assertSame($correctedId, $original->superseded_by_punch_id);
    }

    public function test_correcting_a_punch_resyncs_the_attendance_day_when_it_becomes_consistent(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        // 打刻漏れ・重複を想定し、clock_inが2件記録されている(矛盾あり、日次未反映)。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $mistakenId = $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T18:05:00+09:00')
            ->assertSuccessful()->json('id');

        $this->assertNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first());

        // 2件目は本来clock_outだった。訂正すると矛盾が解消され、日次勤怠に反映される。
        $this->actingAs($employee)->putJson("/api/attendance-punches/{$mistakenId}", [
            'punch_type' => 'clock_out',
            'punched_at' => '2026-07-09T18:00:00+09:00',
            'reason' => '打刻種別の選択ミス(退勤のつもりが出勤になっていた)',
        ])->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertNotNull($day);
        $this->assertSame('punch', $day->source);
        // 休憩なしの09:00〜18:00勤務なので実働9時間(540分)。
        $this->assertSame(540, $day->calculation->actual_work_minutes);
    }

    public function test_deleting_a_duplicate_punch_resyncs_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $duplicateId = $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:05:00+09:00')
            ->assertSuccessful()->json('id');
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T18:00:00+09:00')->assertSuccessful();

        $this->assertNull(AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first());

        $response = $this->actingAs($employee)->deleteJson("/api/attendance-punches/{$duplicateId}", [
            'reason' => '二重打刻の削除',
        ]);
        $response->assertOk();
        $response->assertJsonPath('status', 'deleted');
        $response->assertJsonPath('correction_reason', '二重打刻の削除');

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertNotNull($day);
        $this->assertSame('punch', $day->source);
    }

    public function test_corrected_and_deleted_punches_remain_visible_in_the_index_with_their_status(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $punchId = $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:30:00+09:00')
            ->assertSuccessful()->json('id');
        $this->actingAs($employee)->putJson("/api/attendance-punches/{$punchId}", [
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00+09:00',
            'reason' => '打刻時刻の入力ミス',
        ])->assertSuccessful();

        $response = $this->actingAs($employee)->getJson("/api/attendance-punches?from={$workDate}&to={$workDate}");
        $response->assertOk();
        $statuses = collect($response->json())->pluck('status')->sort()->values()->all();
        $this->assertSame(['active', 'corrected'], $statuses);
    }

    public function test_correcting_an_already_corrected_punch_is_rejected(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $punchId = $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:30:00+09:00')
            ->assertSuccessful()->json('id');
        $this->actingAs($employee)->putJson("/api/attendance-punches/{$punchId}", [
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00+09:00',
            'reason' => '1回目の訂正',
        ])->assertSuccessful();

        $this->actingAs($employee)->putJson("/api/attendance-punches/{$punchId}", [
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:15:00+09:00',
            'reason' => '2回目の訂正',
        ])->assertStatus(422);
    }

    public function test_deleting_an_already_deleted_punch_is_rejected(): void
    {
        $employee = User::factory()->create();
        $punchId = $this->recordPunch($employee, '2026-07-09', 'clock_in', '2026-07-09T09:00:00+09:00')
            ->assertSuccessful()->json('id');

        $this->actingAs($employee)->deleteJson("/api/attendance-punches/{$punchId}", ['reason' => '削除1'])->assertOk();
        $this->actingAs($employee)->deleteJson("/api/attendance-punches/{$punchId}", ['reason' => '削除2'])->assertStatus(422);
    }

    public function test_correcting_or_deleting_another_users_punch_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $punchId = $this->recordPunch($employee, '2026-07-09', 'clock_in', '2026-07-09T09:00:00+09:00')
            ->assertSuccessful()->json('id');

        $this->actingAs($other)->putJson("/api/attendance-punches/{$punchId}", [
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T08:00:00+09:00',
            'reason' => '他人の打刻を訂正しようとするテスト',
        ])->assertForbidden();

        $this->actingAs($other)->deleteJson("/api/attendance-punches/{$punchId}", ['reason' => 'テスト'])
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->putJson("/api/attendance-punches/{$punchId}", [
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T08:00:00+09:00',
            'reason' => '管理者による訂正',
        ])->assertSuccessful();
    }

    /**
     * 打刻の訂正・削除による日次勤怠への再反映は、締め・承認済み月の日次勤怠を
     * 上書きしない(UC-A005/UC-A015と同じ制約。矛盾のない実装であることの確認)。
     */
    public function test_punch_correction_does_not_resync_a_day_once_its_month_is_approved(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $workDate = '2026-07-09';

        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $clockOutId = $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T18:00:00+09:00')
            ->assertSuccessful()->json('id');

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertNotNull($day);

        $this->actingAs($employee)->postJson('/api/attendance/months/2026-07/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', '2026-07')->first()->id;
        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/approve")->assertOk();

        // 承認後(締め前)に打刻を訂正しても、日次勤怠は上書きされない。
        $this->actingAs($employee)->putJson("/api/attendance-punches/{$clockOutId}", [
            'punch_type' => 'clock_out',
            'punched_at' => '2026-07-09T20:00:00+09:00',
            'reason' => '承認後に訂正を試みるテスト',
        ])->assertSuccessful();

        $day->refresh();
        $this->assertSame(
            '2026-07-09T18:00:00+09:00',
            LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes),
        );
    }
}
