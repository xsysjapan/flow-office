<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\AttendanceDay;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 出勤率Assessment。spec.md論点9。
 */
class AttendanceRateAssessorTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function seedSchedule(User $user, WorkStyle $workStyle, Carbon $start, int $days, int $attendedDays): void
    {
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date->toDateString(), 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);

            if ($i < $attendedDays) {
                AttendanceDay::query()->create([
                    'user_id' => $user->id, 'work_date' => $date->toDateString(),
                    'status' => 'clocked_out', 'source' => 'live',
                ]);
            }
        }
    }

    public function test_zero_denominator_days_is_needs_review(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);

        $result = (new AttendanceRateAssessor)->assess($user, Carbon::parse('2025-10-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(ScheduleEntryStatus::NEEDS_REVIEW, $result['automaticResult']);
        $this->assertSame(0, $result['denominatorDays']);
        $this->assertNull($result['attendanceRate']);
    }

    public function test_attendance_rate_80_or_more_is_eligible(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $workStyle = $this->createWorkStyle();
        $periodStart = Carbon::parse('2026-01-01');
        $this->seedSchedule($user, $workStyle, $periodStart, 10, 8);

        $result = (new AttendanceRateAssessor)->assess($user, $periodStart, $periodStart->copy()->addDays(9));

        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $result['automaticResult']);
        $this->assertSame(10, $result['denominatorDays']);
        $this->assertSame(8, $result['attendanceDays']);
        $this->assertEquals(80.0, $result['attendanceRate']);
    }

    public function test_attendance_rate_below_80_is_not_eligible(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01']);
        $workStyle = $this->createWorkStyle();
        $periodStart = Carbon::parse('2026-01-01');
        $this->seedSchedule($user, $workStyle, $periodStart, 10, 7);

        $result = (new AttendanceRateAssessor)->assess($user, $periodStart, $periodStart->copy()->addDays(9));

        $this->assertSame(ScheduleEntryStatus::NOT_ELIGIBLE, $result['automaticResult']);
        $this->assertEquals(70.0, $result['attendanceRate']);
    }

    public function test_period_spanning_before_usage_start_date_without_migration_data_is_needs_review(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01', 'usage_start_date' => '2026-01-05']);
        $workStyle = $this->createWorkStyle();
        $periodStart = Carbon::parse('2026-01-01');
        // usage_start_date(2026-01-05)より前の期間にはAttendanceDayを一切作らない
        // (移行データ無し)。
        $this->seedSchedule($user, $workStyle, $periodStart, 10, 0);

        $result = (new AttendanceRateAssessor)->assess($user, $periodStart, $periodStart->copy()->addDays(9));

        $this->assertSame(ScheduleEntryStatus::NEEDS_REVIEW, $result['automaticResult']);
        $this->assertNull($result['attendanceRate']);
    }

    public function test_period_spanning_before_usage_start_date_with_migration_data_is_assessed_normally(): void
    {
        $user = User::factory()->create(['hire_date' => '2024-01-01', 'usage_start_date' => '2026-01-05']);
        $workStyle = $this->createWorkStyle();
        $periodStart = Carbon::parse('2026-01-01');
        // usage_start_date以前にも移行データ(AttendanceDay)が存在する。
        $this->seedSchedule($user, $workStyle, $periodStart, 10, 9);

        $result = (new AttendanceRateAssessor)->assess($user, $periodStart, $periodStart->copy()->addDays(9));

        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $result['automaticResult']);
        $this->assertEquals(90.0, $result['attendanceRate']);
    }
}
