<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `AttendanceRateAssessor`の単体テスト。既存`GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`
 * と同一の分母/分子定義を検証しつつ、NeedsReview拡張(データ不足・usage_start_date跨ぎ)を
 * 確認する(spec.md 論点9、検証方法節)。
 */
class AttendanceRateAssessorTest extends TestCase
{
    use RefreshDatabase;

    private ?string $workStyleId = null;

    private function workStyleId(): string
    {
        if ($this->workStyleId === null) {
            $this->workStyleId = WorkStyle::query()->create([
                'code' => 'TEST',
                'name' => 'テスト勤務形態',
                'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
                'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 2400,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
            ])->id;
        }

        return $this->workStyleId;
    }

    private function scheduleWorkingDay(User $user, string $date, bool $isWorkingDay = true): void
    {
        EmployeeCalendarEntry::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'work_style_id' => $this->workStyleId(),
            'day_type' => $isWorkingDay ? 'weekday' : 'company_holiday',
            'is_working_day' => $isWorkingDay,
        ]);
    }

    private function recordAttendance(User $user, string $date, string $status = AttendanceDayStatus::CLOCKED_OUT, string $workType = 'normal'): void
    {
        AttendanceDay::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'status' => $status,
            'work_type' => $workType,
        ]);
    }

    public function test_attendance_rate_at_exactly_80_percent_is_eligible(): void
    {
        $user = User::factory()->create();

        // 分母5日、出勤4日 = 80%ちょうど。
        for ($i = 1; $i <= 5; $i++) {
            $this->scheduleWorkingDay($user, "2025-0{$i}-01");
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->recordAttendance($user, "2025-0{$i}-01");
        }

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-05-01'),
            minAttendanceRate: 80.0,
        );

        $this->assertSame(5, $result->denominatorDays);
        $this->assertSame(4, $result->attendanceDays);
        $this->assertEqualsWithDelta(80.0, $result->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $result->automaticResult);
    }

    public function test_attendance_rate_just_below_80_percent_is_not_eligible(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->scheduleWorkingDay($user, sprintf('2025-01-%02d', $i));
        }
        for ($i = 1; $i <= 7; $i++) {
            $this->recordAttendance($user, sprintf('2025-01-%02d', $i));
        }

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-01-31'),
            minAttendanceRate: 80.0,
        );

        $this->assertEqualsWithDelta(70.0, $result->attendanceRate, 0.001);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $result->automaticResult);
    }

    public function test_paid_leave_taken_days_count_as_attended(): void
    {
        $user = User::factory()->create();

        $this->scheduleWorkingDay($user, '2025-01-01');
        $this->scheduleWorkingDay($user, '2025-01-02');
        $this->recordAttendance($user, '2025-01-01', AttendanceDayStatus::NOT_STARTED, 'paid_leave_full');

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-01-31'),
            minAttendanceRate: 50.0,
        );

        $this->assertSame(1, $result->attendanceDays);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $result->automaticResult);
    }

    public function test_zero_denominator_is_needs_review(): void
    {
        $user = User::factory()->create();

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-01-31'),
            minAttendanceRate: 80.0,
        );

        $this->assertSame(0, $result->denominatorDays);
        $this->assertNull($result->attendanceRate);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $result->automaticResult);
    }

    public function test_period_crossing_usage_start_date_without_migrated_data_is_needs_review(): void
    {
        $user = User::factory()->create();
        $this->scheduleWorkingDay($user, '2025-01-01');
        $this->recordAttendance($user, '2025-01-01');

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-06-01'),
            minAttendanceRate: 80.0,
            usageStartDate: Carbon::parse('2025-03-01'),
            hasMigratedLegacyData: false,
        );

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW, $result->automaticResult);
        $this->assertNull($result->attendanceRate);
    }

    public function test_period_crossing_usage_start_date_with_migrated_data_is_assessed_normally(): void
    {
        $user = User::factory()->create();
        $this->scheduleWorkingDay($user, '2025-01-01');
        $this->recordAttendance($user, '2025-01-01');

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-06-01'),
            minAttendanceRate: 80.0,
            usageStartDate: Carbon::parse('2025-03-01'),
            hasMigratedLegacyData: true,
        );

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $result->automaticResult);
    }

    public function test_period_entirely_after_usage_start_date_is_assessed_normally(): void
    {
        $user = User::factory()->create();
        $this->scheduleWorkingDay($user, '2025-04-01');
        $this->recordAttendance($user, '2025-04-01');

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-04-01'),
            periodEnd: Carbon::parse('2025-06-01'),
            minAttendanceRate: 80.0,
            usageStartDate: Carbon::parse('2025-03-01'),
            hasMigratedLegacyData: false,
        );

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $result->automaticResult);
    }

    public function test_non_working_days_are_not_counted_in_denominator(): void
    {
        $user = User::factory()->create();

        $this->scheduleWorkingDay($user, '2025-01-01', false);
        $this->scheduleWorkingDay($user, '2025-01-02');
        $this->recordAttendance($user, '2025-01-02');

        $result = (new AttendanceRateAssessor())->assess(
            userId: $user->id,
            periodStart: Carbon::parse('2025-01-01'),
            periodEnd: Carbon::parse('2025-01-31'),
            minAttendanceRate: 80.0,
        );

        $this->assertSame(1, $result->denominatorDays);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $result->automaticResult);
    }
}
