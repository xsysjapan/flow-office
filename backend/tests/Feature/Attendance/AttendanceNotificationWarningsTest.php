<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceMonth;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-N001「勤怠未提出」「月次締め前警告」。
 */
class AttendanceNotificationWarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_warns_active_users_who_have_not_submitted_last_months_attendance_once_past_the_deadline(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);

        $submitted = User::factory()->create(['employment_status' => 'active']);
        AttendanceMonth::query()->create(['user_id' => $submitted->id, 'year_month' => '2026-06', 'status' => 'submitted']);

        $unsubmitted = User::factory()->create(['employment_status' => 'active']);
        $inactive = User::factory()->create(['employment_status' => 'resigned']);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-06'));

        $this->assertSame(1, $count);
    }

    public function test_does_not_warn_before_the_submission_deadline_day(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);
        User::factory()->create(['employment_status' => 'active']);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-03'));

        $this->assertSame(0, $count);
    }

    public function test_warns_about_months_not_yet_closed_within_the_warning_window_before_the_deadline(): void
    {
        SystemSetting::current()->update(['attendance_month_close_deadline_day' => 10]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        AttendanceMonth::query()->create(['user_id' => $userA->id, 'year_month' => '2026-06', 'status' => 'approved']);
        AttendanceMonth::query()->create(['user_id' => $userB->id, 'year_month' => '2026-06', 'status' => 'closed']);

        // 締め切り(10日)の3日前(7日)。
        $count = app(CommandBus::class)->dispatch(new WarnMonthCloseDeadline(asOf: '2026-07-07'));

        $this->assertSame(1, $count);
    }

    public function test_does_not_warn_outside_the_warning_window(): void
    {
        SystemSetting::current()->update(['attendance_month_close_deadline_day' => 10]);
        $user = User::factory()->create();
        AttendanceMonth::query()->create(['user_id' => $user->id, 'year_month' => '2026-06', 'status' => 'approved']);

        $count = app(CommandBus::class)->dispatch(new WarnMonthCloseDeadline(asOf: '2026-07-01'));

        $this->assertSame(0, $count);
    }
}
