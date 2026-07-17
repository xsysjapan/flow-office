<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日次登録後、区分ごとの時間(所定労働・残業・深夜・休日労働)を手動で補正する。
 * 実績(出勤・退勤・休憩)が再編集され再計算されると、この補正は解除される
 * (AttendanceDailyCalculationProjector参照)。
 */
class AttendanceDailyCalculationAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_calculated_breakdown_can_be_manually_adjusted(): void
    {
        $employee = User::factory()->create();
        $dateString = '2026-07-09';

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T18:00:00+09:00",
            'breaks' => [['start' => "{$dateString}T12:00:00+09:00", 'end' => "{$dateString}T13:00:00+09:00"]],
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}/calculation", [
            'prescribed_work_minutes' => 480,
            'statutory_within_overtime_minutes' => 30,
            'statutory_excess_overtime_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'late_night_prescribed_work_minutes' => 0,
            'late_night_statutory_within_overtime_minutes' => 0,
            'late_night_statutory_excess_overtime_minutes' => 0,
            'late_night_legal_holiday_work_minutes' => 0,
            'reason' => '休憩の取り方を考慮して補正',
        ]);

        $response->assertOk();
        $calculation = $response->json('calculation');
        $this->assertSame(30, $calculation['statutory_within_overtime_minutes']);
        $this->assertTrue($calculation['is_manually_adjusted']);
    }

    public function test_re_editing_the_day_resets_the_manual_adjustment(): void
    {
        $employee = User::factory()->create();
        $dateString = '2026-07-09';

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T18:00:00+09:00",
            'breaks' => [],
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}/calculation", [
            'prescribed_work_minutes' => 480,
            'statutory_within_overtime_minutes' => 30,
            'statutory_excess_overtime_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'late_night_prescribed_work_minutes' => 0,
            'late_night_statutory_within_overtime_minutes' => 0,
            'late_night_statutory_excess_overtime_minutes' => 0,
            'late_night_legal_holiday_work_minutes' => 0,
            'reason' => '補正',
        ])->assertOk()->assertJsonPath('calculation.is_manually_adjusted', true);

        $editResponse = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => "{$dateString}T09:00:00+09:00",
            'actual_end_at' => "{$dateString}T18:00:00+09:00",
            'breaks' => [],
            'reason' => '実績の再編集',
        ]);

        $editResponse->assertOk();
        $calculation = $editResponse->json('calculation');
        $this->assertFalse($calculation['is_manually_adjusted']);
        $this->assertNotSame(30, $calculation['statutory_within_overtime_minutes']);
    }

    public function test_adjusting_another_users_day_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $dateString = '2026-07-09';

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $this->actingAs($other)->putJson("/api/attendance/days/{$dayId}/calculation", [
            'prescribed_work_minutes' => 480,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'late_night_prescribed_work_minutes' => 0,
            'late_night_statutory_within_overtime_minutes' => 0,
            'late_night_statutory_excess_overtime_minutes' => 0,
            'late_night_legal_holiday_work_minutes' => 0,
            'reason' => '他人の日次を補正しようとするテスト',
        ])->assertForbidden();
    }

    public function test_adjusting_a_locked_day_is_rejected(): void
    {
        $employee = User::factory()->create();
        $dateString = '2026-07-09';

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'reason' => '登録',
        ])->assertCreated()->json('id');

        AttendanceDay::query()->whereKey($dayId)->update(['locked_at' => now()]);

        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}/calculation", [
            'prescribed_work_minutes' => 480,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'late_night_prescribed_work_minutes' => 0,
            'late_night_statutory_within_overtime_minutes' => 0,
            'late_night_statutory_excess_overtime_minutes' => 0,
            'late_night_legal_holiday_work_minutes' => 0,
            'reason' => '締め後の補正テスト',
        ])->assertStatus(422);
    }
}
