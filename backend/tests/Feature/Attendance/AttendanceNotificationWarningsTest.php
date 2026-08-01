<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\EventSourcing\CommandBus;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
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

        $submitted = User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);
        AttendanceMonth::query()->create(['user_id' => $submitted->id, 'year_month' => '2026-06', 'status' => 'submitted']);

        $unsubmitted = User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);
        $inactive = User::factory()->create(['employment_status' => 'resigned', 'usage_start_date' => '2026-01-01']);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-06'));

        $this->assertSame(1, $count);
    }

    public function test_does_not_warn_before_the_submission_deadline_day(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);
        User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-03'));

        $this->assertSame(0, $count);
    }

    public function test_does_not_warn_users_whose_usage_start_date_or_hire_date_is_after_the_target_month(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);

        // 対象月(2026-06)より後に利用開始 → フォロー対象外。
        User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-07-01']);
        // 利用開始日は対象月以前だが、対象月より後に入社 → フォロー対象外。
        User::factory()->create([
            'employment_status' => 'active',
            'usage_start_date' => '2026-01-01',
            'hire_date' => '2026-07-01',
        ]);
        // 対象月中に利用開始・入社済み → フォロー対象。
        User::factory()->create([
            'employment_status' => 'active',
            'usage_start_date' => '2026-06-01',
            'hire_date' => '2026-01-01',
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-06'));

        $this->assertSame(1, $count);
    }

    public function test_does_not_warn_users_whose_usage_start_date_is_unset(): void
    {
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 5]);

        User::factory()->create(['employment_status' => 'active', 'usage_start_date' => null]);

        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance(asOf: '2026-07-06'));

        $this->assertSame(0, $count);
    }

    public function test_determines_the_target_month_using_the_default_timezone_not_the_server_timezone(): void
    {
        // config('app.timezone')はUTCだが、system_settings.default_timezoneは既定でAsia/Tokyo。
        // UTC 2026-06-30 20:00は日本時間では2026-07-01 05:00であり、日付・月をまたぐ。
        // asOfを渡さない(cronからの実行を模した)場合、UTCの「今日」(6/30)ではなく
        // Asia/Tokyoの「今日」(7/1)を基準に、対象月を6月と判定できていることを確認する。
        $this->assertSame('Asia/Tokyo', SystemSetting::current()->default_timezone);
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 1]);

        $user = User::factory()->create(['employment_status' => 'active', 'usage_start_date' => '2026-01-01']);

        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-30 20:00:00', 'UTC'));
        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance);

        $this->assertSame(1, $count);
        Queue::assertPushed(SendNotificationJob::class, fn ($job) => str_contains($job->summary, '2026-06'));
    }

    public function test_determines_each_users_target_month_using_their_own_timezone_not_the_company_default(): void
    {
        // UTC 2026-07-01 01:00は、Asia/Tokyo(UTC+9)では2026-07-01 10:00(7/1)だが、
        // America/Los_Angeles(夏時間UTC-7)では2026-06-30 18:00(6/30)であり、日付・月が
        // ユーザーごとに異なる。asOfを渡さない場合、会社既定のタイムゾーン(Asia/Tokyo)を
        // 全員に適用するのではなく、各社員の`users.timezone`基準の「今日」で対象月を
        // 判定できていることを確認する。
        $this->assertSame('Asia/Tokyo', SystemSetting::current()->default_timezone);
        SystemSetting::current()->update(['attendance_submission_deadline_day' => 1]);

        $tokyoUser = User::factory()->create([
            'employment_status' => 'active',
            'usage_start_date' => '2026-01-01',
            'timezone' => 'Asia/Tokyo',
        ]);
        $laUser = User::factory()->create([
            'employment_status' => 'active',
            'usage_start_date' => '2026-01-01',
            'timezone' => 'America/Los_Angeles',
        ]);

        Queue::fake();
        $this->travelTo(Carbon::parse('2026-07-01 01:00:00', 'UTC'));
        $count = app(CommandBus::class)->dispatch(new WarnUnsubmittedAttendance);

        // Tokyoの「今日」は2026-07-01(対象月2026-06)、LAの「今日」は2026-06-30(対象月2026-05)。
        $this->assertSame(2, $count);
        Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->recipientUserId === $tokyoUser->id && str_contains($job->summary, '2026-06'));
        Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->recipientUserId === $laUser->id && str_contains($job->summary, '2026-05'));
    }

    public function test_warns_about_months_not_yet_closed_within_the_warning_window_before_the_deadline(): void
    {
        SystemSetting::current()->update(['attendance_month_close_deadline_day' => 10]);
        $userA = User::factory()->create(['usage_start_date' => '2026-01-01']);
        $userB = User::factory()->create(['usage_start_date' => '2026-01-01']);
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

    public function test_month_close_warning_excludes_users_whose_usage_start_date_is_after_the_target_month(): void
    {
        SystemSetting::current()->update(['attendance_month_close_deadline_day' => 10]);

        $notYetUsing = User::factory()->create(['usage_start_date' => '2026-07-01']);
        AttendanceMonth::query()->create(['user_id' => $notYetUsing->id, 'year_month' => '2026-06', 'status' => 'approved']);

        $count = app(CommandBus::class)->dispatch(new WarnMonthCloseDeadline(asOf: '2026-07-07'));

        $this->assertSame(0, $count);
    }
}
