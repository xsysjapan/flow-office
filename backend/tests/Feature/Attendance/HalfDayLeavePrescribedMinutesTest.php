<?php

namespace Tests\Feature\Attendance;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\SpecialLeaveType;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 半休の日は所定労働時間を所定労働時間の半分とする(AttendanceCalculator・LeaveCalculationInput参照)。
 * 全休・午前+午後の半休・通常勤務日・時間単位休暇は変更しない。
 * 休暇は休暇申請のイベント(有給は申請API、特別休暇は申請集約)で作り、休暇ビューから計算に読まれることを確認する
 * (work_typeの休暇値は計算に使わない)。.claude/skills/attendance-calc-review 参照。
 */
class HalfDayLeavePrescribedMinutesTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(int $prescribedDailyMinutes = 480): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
    }

    /**
     * 休暇を休暇申請のイベントとして記録する。$kind は paid(有給)または special(特別休暇)、$unit は
     * full / am_half / pm_half / hourly。
     */
    private function applyLeave(User $user, string $workDate, string $kind, string $unit, ?float $hours = null): void
    {
        $approver = User::factory()->create();

        if ($kind === 'paid') {
            app(CommandBus::class)->dispatch(new GrantPaidLeave($user->id, '2025-07-01', '2027-06-30', 10.0, null));

            $this->actingAs($user)->postJson('/api/paid-leave/requests', [
                'target_date' => $workDate,
                'leave_type' => $unit,
                'hours' => $hours,
                'approver_user_id' => $approver->id,
            ])->assertCreated();

            return;
        }

        $type = SpecialLeaveType::query()->create(['name' => 'テスト休暇'.uniqid(), 'is_active' => true]);
        SpecialLeaveRequestAggregate::retrieve((string) Str::uuid())->request(
            userId: $user->id,
            specialLeaveTypeId: $type->id,
            targetDate: $workDate,
            leaveType: $unit,
            hours: $hours,
            requestedDays: $unit === 'full' ? 1.0 : 0.5,
            approverUserId: $approver->id,
            reason: null,
        )->persist();
    }

    private function recordDay(
        User $user,
        WorkStyle $workStyle,
        string $workDate,
        ?string $actualStart,
        ?string $actualEnd,
        ?string $leaveKind,
        ?string $leaveUnit = null,
        ?float $leaveHours = null,
    ): AttendanceDay {
        $shift = EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false,
            'is_company_holiday' => false, 'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'calendar_entry_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        if ($leaveKind !== null && $leaveUnit !== null) {
            $this->applyLeave($user, $workDate, $leaveKind, $leaveUnit, $leaveHours);
        }

        $payload = [
            'work_type' => null,
            'reason' => 'テストデータ投入',
        ];
        if ($actualStart !== null && $actualEnd !== null) {
            $payload['actual_start_at'] = "{$workDate}T{$actualStart}:00+09:00";
            $payload['actual_end_at'] = "{$workDate}T{$actualEnd}:00+09:00";
        }

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", $payload)->assertOk();

        return $day->refresh();
    }

    public function test_special_leave_pm_half_halves_the_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '12:00', 'special', 'pm_half');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(240, $response->json('calculation.prescribed_work_minutes'));
        $this->assertSame(180, $response->json('calculation.work_minutes'));
        // 3時間しか働いていないため、半分の所定(240分)にも届かず残業は発生しない。
        $this->assertSame(0, $response->json('calculation.statutory_within_overtime_minutes'));
        $this->assertSame(0, $response->json('calculation.statutory_excess_overtime_minutes'));
    }

    public function test_paid_leave_am_half_halves_the_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '13:00', '17:00', 'paid', 'am_half');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(240, $response->json('calculation.prescribed_work_minutes'));
        $this->assertSame(240, $response->json('calculation.work_minutes'));
        $this->assertSame(0, $response->json('calculation.statutory_within_overtime_minutes'));
        $this->assertSame(0, $response->json('calculation.statutory_excess_overtime_minutes'));
    }

    public function test_full_day_leave_keeps_the_full_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', null, null, 'special', 'full');

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(480, $response->json('calculation.prescribed_work_minutes'));
    }

    public function test_an_ordinary_working_day_keeps_the_full_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '18:00', null);

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(480, $response->json('calculation.prescribed_work_minutes'));
    }

    public function test_hourly_leave_keeps_the_full_prescribed_work_minutes(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $day = $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '18:00', 'paid', 'hourly', 2.0);

        $response = $this->actingAs($user)->getJson("/api/attendance/days/{$day->id}")->assertOk();

        $this->assertSame(480, $response->json('calculation.prescribed_work_minutes'));
    }

    /**
     * 月次確認画面の集計(MonthlyOvertimeCalculator::calculateCategoryTotals)の
     * prescribed_work_minutes合計に、半休日の按分後の値が正しく反映されることを確認する。
     */
    public function test_monthly_totals_reflect_the_halved_prescribed_minutes_for_a_half_day_leave_day(): void
    {
        $workStyle = $this->makeWorkStyle(480);
        $user = User::factory()->create();

        $this->recordDay($user, $workStyle, '2026-08-03', '09:00', '18:00', null);
        $this->recordDay($user, $workStyle, '2026-08-04', '09:00', '12:00', 'special', 'pm_half');

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-08')->assertOk();
        $totals = $response->json('monthly_calculation_totals');

        $this->assertSame(480 + 240, $totals['prescribed_work_minutes']);
    }
}
