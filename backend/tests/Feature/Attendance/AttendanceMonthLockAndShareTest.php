<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLock;
use App\Models\AttendanceMonth;
use App\Models\EntityShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-A008/UC-A010: 月次勤怠の提出時ロック・承認者への共有、差戻し時の解除。
 */
class AttendanceMonthLockAndShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_month_locks_the_period_and_shares_it_with_the_approver(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful()->assertJsonPath('status', 'submitted');

        $month = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail();

        $lock = AttendanceLock::query()->where('user_id', $employee->id)->first();
        $this->assertNotNull($lock);
        $this->assertSame('month', $lock->scope_type);
        $this->assertSame($today->copy()->startOfMonth()->toDateString(), $lock->period_start_date->toDateString());
        $this->assertSame($today->copy()->endOfMonth()->toDateString(), $lock->period_end_date->toDateString());
        $this->assertNull($lock->unlocked_at);

        $share = EntityShare::query()
            ->where('shareable_type', 'attendance_month')
            ->where('shareable_id', $month->id)
            ->first();
        $this->assertNotNull($share);
        $this->assertSame($approver->id, $share->shared_with_user_id);
        $this->assertSame($employee->id, $share->shared_by_user_id);
    }

    public function test_editing_a_day_covered_by_an_active_lock_is_rejected(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'reason' => 'ロック中の編集テスト(拒否されるべき)',
        ])->assertStatus(422);
    }

    public function test_returning_a_month_unlocks_the_period_so_editing_is_allowed_again(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $yearMonth = $today->format('Y-m');

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        $dayId = $this->actingAs($employee)->getJson('/api/attendance/today')->json('id');

        $this->actingAs($employee)->postJson("/api/attendance/months/{$yearMonth}/submit", [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();
        $monthId = AttendanceMonth::query()->where('user_id', $employee->id)->where('year_month', $yearMonth)->firstOrFail()->id;

        $this->actingAs($approver)->postJson("/api/attendance-months/{$monthId}/return", [
            'comment' => '差戻しテスト',
        ])->assertOk()->assertJsonPath('status', 'returned');

        $lock = AttendanceLock::query()->where('user_id', $employee->id)->first();
        $this->assertNotNull($lock->unlocked_at);

        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'reason' => '差戻し後の再編集テスト',
        ])->assertSuccessful();
    }
}
