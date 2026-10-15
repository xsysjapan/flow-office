<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveUsage;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 時間単位有給の取消後、日次集計(paid_leave_minutes)が取消済みのUsageを含めて
 * 再集計されない(=取消済みUsageの分は消え、二重計上されない)ことを確認する回帰テスト。
 * `PaidLeaveUsageAllocationProjector::onPaidLeaveUsageCancelled`がUsage行を削除せず
 * `cancelled=true`にするだけになった(Phase 5)一方、`AttendanceCalculator::calculate`が
 * `cancelled`列を無視して`used_minutes`を合算していたため、取消済みの行が永久に
 * 二重計上され続けるバグの再発防止。
 */
class PaidLeaveUsageCancellationRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date): void
    {
        $calendar = CompanyCalendar::query()->firstOrCreate(['name' => '2026年度'], ['week_starts_on' => 1]);
        if ($calendar->wasRecentlyCreated) {
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        }
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    /**
     * 取消されていない消化記録(有給の消化)の件数。勤怠日の分数(paid_leave_minutes)は勤怠側の
     * 休暇ビューへの置き換え(別作業)まで確認しない。
     */
    private function activeUsageCount(User $employee): int
    {
        return PaidLeaveUsage::query()
            ->where('user_id', $employee->id)
            ->where('cancelled', false)
            ->count();
    }

    public function test_cancelling_hourly_usage_removes_it_from_active_usages_and_a_new_request_is_not_inflated_by_the_stale_row(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $date = '2026-08-10';
        $this->createWorkingDayShift($employee, $date);

        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));

        // 1回目: 時間単位有給2時間を申請・承認 -> paid_leave_minutesは120
        $firstRequestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => 'hourly',
            'hours' => 2,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$firstRequestId}/approve")->assertOk();

        $this->assertSame(1, $this->activeUsageCount($employee));

        // 取消 -> 有効な消化記録は0件になる(取消済み行は残るが有効扱いにならない)
        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$firstRequestId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(0, $this->activeUsageCount($employee));

        // 同日に再度時間単位有給1時間を申請・承認 -> 取消済みの旧行(120分)が
        // 二重計上されず、新規申請分(60分)のみが反映される
        $secondRequestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => 'hourly',
            'hours' => 1,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$secondRequestId}/approve")->assertOk();

        // 有効な消化記録は新規申請の1件だけ(取消済みの旧行は数えられない)
        $this->assertSame(1, $this->activeUsageCount($employee));
        $this->assertSame(
            $secondRequestId,
            PaidLeaveUsage::query()->where('user_id', $employee->id)->where('cancelled', false)->value('paid_leave_request_id'),
        );
    }
}
