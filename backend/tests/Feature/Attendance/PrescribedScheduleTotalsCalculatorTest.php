<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\Attendance\Services\PrescribedScheduleTotalsCalculator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\AttendanceDay;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 勤務予定ベースの所定労働日数・所定労働時間(PrescribedScheduleTotalsCalculator)。
 * 働き方 P=480・会社カレンダー無し(土日休みのフォールバック。祝日は考慮されない)の2026-11は、
 * 平日21日(11/2-6,9-13,16-20,23-27,30)で 21日 / 10080分。入力の進み具合・休暇では変わらない。
 * docs/changesets/20261114-prescribed-minutes-no-leave-reduction/spec.md 論点7。
 */
class PrescribedScheduleTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $workStyle = WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'is_shift_based' => false,
        ]);
        $user = User::factory()->create();
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id, 'year_month' => '2026-11',
            'work_style_id' => $workStyle->id, 'assigned_by_user_id' => $user->id,
        ]);

        return $user;
    }

    /** @return array{prescribed_work_days: int, scheduled_prescribed_work_minutes: int} */
    private function monthTotals(User $user): array
    {
        return app(PrescribedScheduleTotalsCalculator::class)->calculate($user->id, '2026-11-01', '2026-11-30');
    }

    private function createDay(User $user, string $date): string
    {
        return $this->actingAs($user)->postJson('/api/attendance/days', [
            'user_id' => $user->id, 'work_date' => $date, 'reason' => '登録',
        ])->assertCreated()->json('id');
    }

    private function recordDay(User $user, string $date, string $start = '09:00', string $end = '18:00', bool $withBreak = true): string
    {
        // 休暇申請の勤怠日が既にあればそれを使う。
        $dayId = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', $date)->value('id')
            ?? $this->createDay($user, $date);
        $this->actingAs($user)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => "{$date}T{$start}:00+09:00",
            'actual_end_at' => "{$date}T{$end}:00+09:00",
            'breaks' => $withBreak ? [['start' => "{$date}T12:00:00+09:00", 'end' => "{$date}T13:00:00+09:00"]] : [],
            'reason' => 'テストデータ投入',
        ])->assertOk();

        return $dayId;
    }

    private function requestLeave(User $user, string $date, string $unit): void
    {
        $approver = User::factory()->create();
        $this->actingAs($user)->postJson('/api/paid-leave/requests', [
            'target_date' => $date, 'leave_type' => $unit, 'hours' => null, 'approver_user_id' => $approver->id,
        ])->assertCreated();
    }

    public function test_a_month_without_any_input_counts_the_scheduled_weekdays(): void
    {
        $user = $this->makeUser();

        $this->assertSame(
            ['prescribed_work_days' => 21, 'scheduled_prescribed_work_minutes' => 10080],
            $this->monthTotals($user),
        );
    }

    public function test_entered_days_do_not_change_the_totals(): void
    {
        $user = $this->makeUser();
        $this->recordDay($user, '2026-11-02');
        $this->recordDay($user, '2026-11-04', '09:00', '20:00');

        $this->assertSame(
            ['prescribed_work_days' => 21, 'scheduled_prescribed_work_minutes' => 10080],
            $this->monthTotals($user),
        );
    }

    public function test_leave_days_do_not_reduce_the_totals(): void
    {
        $user = $this->makeUser();
        app(CommandBus::class)->dispatch(new GrantPaidLeave($user->id, '2025-07-01', '2027-06-30', 10.0, null));
        $this->requestLeave($user, '2026-11-02', 'full');
        $this->requestLeave($user, '2026-11-04', 'am_half');
        $this->recordDay($user, '2026-11-04', '13:00', '18:00', withBreak: false);

        $this->assertSame(
            ['prescribed_work_days' => 21, 'scheduled_prescribed_work_minutes' => 10080],
            $this->monthTotals($user),
        );
    }

    public function test_a_manually_adjusted_day_uses_the_adjusted_prescribed_minutes(): void
    {
        $user = $this->makeUser();
        $dayId = $this->recordDay($user, '2026-11-04');

        $this->actingAs($user)->putJson("/api/attendance/days/{$dayId}/calculation", [
            'prescribed_work_minutes' => 300,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'payroll_work_minutes' => null,
            'late_night_prescribed_work_minutes' => 0,
            'late_night_statutory_within_overtime_minutes' => 0,
            'late_night_statutory_excess_overtime_minutes' => 0,
            'late_night_legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 0,
            'late_night_prescribed_holiday_work_minutes' => 0,
            'reason' => '所定労働時間の補正',
        ])->assertOk();

        $this->assertSame(
            ['prescribed_work_days' => 21, 'scheduled_prescribed_work_minutes' => 10080 - 180],
            $this->monthTotals($user),
        );
    }

    public function test_a_company_holiday_schedule_is_not_a_prescribed_work_day(): void
    {
        $user = $this->makeUser();
        $workStyleId = UserWorkStyleMonthlyAssignment::query()->where('user_id', $user->id)->value('work_style_id');
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-11-04', 'work_style_id' => $workStyleId,
            'day_type' => 'company_holiday', 'is_working_day' => true, 'is_legal_holiday' => false,
            'is_company_holiday' => true, 'planned_break_minutes' => 0,
        ]);

        $this->assertSame(
            ['prescribed_work_days' => 20, 'scheduled_prescribed_work_minutes' => 9600],
            $this->monthTotals($user),
        );
    }

    public function test_a_legal_holiday_schedule_is_not_a_prescribed_work_day(): void
    {
        $user = $this->makeUser();
        $workStyleId = UserWorkStyleMonthlyAssignment::query()->where('user_id', $user->id)->value('work_style_id');
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id, 'work_date' => '2026-11-04', 'work_style_id' => $workStyleId,
            'day_type' => 'legal_holiday', 'is_working_day' => true, 'is_legal_holiday' => true,
            'is_company_holiday' => false, 'planned_break_minutes' => 0,
        ]);

        $this->assertSame(
            ['prescribed_work_days' => 20, 'scheduled_prescribed_work_minutes' => 9600],
            $this->monthTotals($user),
        );
    }

    public function test_work_on_a_weekend_does_not_count(): void
    {
        $user = $this->makeUser();
        $this->recordDay($user, '2026-11-07');
        $this->recordDay($user, '2026-11-08');

        $this->assertSame(
            ['prescribed_work_days' => 21, 'scheduled_prescribed_work_minutes' => 10080],
            $this->monthTotals($user),
        );
    }

    public function test_without_any_work_style_the_totals_are_zero(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            ['prescribed_work_days' => 0, 'scheduled_prescribed_work_minutes' => 0],
            app(PrescribedScheduleTotalsCalculator::class)->calculate($user->id, '2026-11-01', '2026-11-30'),
        );
    }

    public function test_the_monthly_category_totals_include_the_schedule_based_totals(): void
    {
        $user = $this->makeUser();
        $this->recordDay($user, '2026-11-02');

        $totals = app(MonthlyOvertimeCalculator::class)->calculateCategoryTotals($user->id, '2026-11');

        $this->assertSame(21, $totals['prescribed_work_days']);
        $this->assertSame(10080, $totals['scheduled_prescribed_work_minutes']);
        // 既存キー(入力済みの日の合計)は変わらない。
        $this->assertSame(480, $totals['prescribed_work_minutes']);

        $response = $this->actingAs($user)->getJson('/api/attendance/months/2026-11')->assertOk();
        $this->assertSame(21, $response->json('monthly_calculation_totals.prescribed_work_days'));
        $this->assertSame(10080, $response->json('monthly_calculation_totals.scheduled_prescribed_work_minutes'));
    }

    public function test_the_week_overtime_response_includes_the_schedule_based_totals(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/attendance/week/overtime?start_date=2026-11-02')->assertOk();

        $this->assertSame(5, $response->json('prescribed_work_days'));
        $this->assertSame(2400, $response->json('scheduled_prescribed_work_minutes'));
        $this->assertSame('2026-11-02', $response->json('week_start_date'));
        $this->assertSame('2026-11-08', $response->json('week_end_date'));
    }
}
