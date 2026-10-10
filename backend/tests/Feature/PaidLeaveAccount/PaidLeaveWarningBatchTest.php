<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-P005: 有給消滅警告を出す(バッチ) / UC-P006: 年5日取得義務を警告する(バッチ)。
 *
 * `paid-leave:grant-scheduled`(旧`GrantScheduledPaidLeaveHandler`、日次全件評価バッチ)の
 * 廃止(docs/changesets/20260906-paid-leave-schedule-assessment/spec.md Phase C・論点10)に
 * 伴い、`tests/Feature/PaidLeaveAccount/PaidLeaveScheduledBatchTest.php`から
 * `WarnExpiringPaidLeave`/`WarnFiveDayObligation`(いずれも本変更セットの対象外、無変更で
 * 存続)のテストのみをこのファイルへ引き継ぐ。付与Schedule関連のテストは
 * `tests/Feature/PaidLeaveSchedule/RollPaidLeaveSchedulesCommandTest.php`へ移行した。
 *
 * 付与・消化はイベント経由で作る(付与は`GrantPaidLeave`、消化は申請・承認APIで作る。
 * 残数・使用日数のReadModelを直接作成しない)。
 */
class PaidLeaveWarningBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_warn_expiring_notifies_and_marks_grants_within_the_warning_window(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $expiringSoonId = app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2024-08-10', '2026-10-01', 10.0, null));
        $expiringLaterId = app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-08-10', '2028-08-10', 10.0, null));
        $this->approvedLeave($employee, $approver, '2026-07-06');
        $this->approvedLeave($employee, $approver, '2026-07-07');

        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave($today->toDateString()));

        $this->assertSame(1, $count);
        $this->assertNotNull(PaidLeaveGrant::query()->findOrFail($expiringSoonId)->expiry_warned_at);
        $this->assertNull(PaidLeaveGrant::query()->findOrFail($expiringLaterId)->expiry_warned_at);
    }

    public function test_warn_expiring_determines_each_users_today_using_their_own_timezone_not_the_company_default(): void
    {
        // UTC 2026-08-10 03:00は、Asia/Tokyo(UTC+9)では2026-08-10 12:00(8/10)だが、
        // America/Los_Angeles(夏時間UTC-7)では2026-08-09 20:00(8/9)であり、日付が
        // ユーザーごとに異なる。asOfを渡さない場合、会社既定のタイムゾーン(Asia/Tokyo)を
        // 全員に適用するのではなく、各社員の`users.timezone`基準の「今日」で
        // 期限切れ判定できていることを確認する(同じexpires_onでも扱いが変わる)。
        $tokyoUser = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $laUser = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $approver = User::factory()->create();

        // Tokyoの「今日」(8/10)からはすでに過ぎているため対象外になるが、
        // LAの「今日」(8/9)からはちょうど当日(下限境界)のため対象になる。
        $tokyoGrantId = app(CommandBus::class)->dispatch(new GrantPaidLeave($tokyoUser->id, '2024-08-10', '2026-08-09', 10.0, null));
        $laGrantId = app(CommandBus::class)->dispatch(new GrantPaidLeave($laUser->id, '2024-08-10', '2026-08-09', 10.0, null));
        $this->approvedLeave($tokyoUser, $approver, '2026-05-11');
        $this->approvedLeave($tokyoUser, $approver, '2026-05-12');
        $this->approvedLeave($laUser, $approver, '2026-05-11');
        $this->approvedLeave($laUser, $approver, '2026-05-12');

        $this->travelTo(Carbon::parse('2026-08-10 03:00:00', 'UTC'));
        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave);

        $this->assertSame(1, $count);
        $this->assertNull(PaidLeaveGrant::query()->findOrFail($tokyoGrantId)->expiry_warned_at);
        $this->assertNotNull(PaidLeaveGrant::query()->findOrFail($laGrantId)->expiry_warned_at);
    }

    public function test_warn_expiring_does_not_renotify_an_already_warned_grant(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2024-08-10', '2026-10-01', 10.0, null));
        $this->approvedLeave($employee, $approver, '2026-07-06');
        $this->approvedLeave($employee, $approver, '2026-07-07');

        // 先に8/1時点で警告済みにする(警告の記録はWarnExpiringPaidLeaveのイベントで行う)。
        $this->assertSame(1, app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave('2026-08-01')));

        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave($today->toDateString()));

        $this->assertSame(0, $count);
    }

    public function test_warn_five_day_obligation_warns_when_usage_is_insufficient_near_the_deadline(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $grantId = app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-09-01', '2027-08-31', 10.0, null));
        $this->approvedLeave($employee, $approver, '2026-06-01');
        $this->approvedLeave($employee, $approver, '2026-06-02');

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(1, $count);
        $this->assertNotNull(PaidLeaveGrant::query()->findOrFail($grantId)->five_day_obligation_warned_at);
    }

    public function test_warn_five_day_obligation_uses_calendar_days_across_user_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 00:00:00', 'Asia/Tokyo'));
        $employee = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-10-08', '2027-10-08', 10.0, null));

        try {
            $this->assertSame(1, app(CommandBus::class)->dispatch(new WarnFiveDayObligation));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_warn_five_day_obligation_skips_when_five_days_are_already_used(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-09-01', '2027-08-31', 10.0, null));
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
            $this->approvedLeave($employee, $approver, $date);
        }

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(0, $count);
    }

    public function test_warn_five_day_obligation_skips_grants_outside_the_warning_window(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        // 義務期限(付与日+1年)が2027-08-31で、警告ウィンドウ(60日前)にまだ入っていない
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2026-08-31', '2028-08-31', 10.0, null));

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(0, $count);
    }

    /**
     * 有給を全休で申請し、承認する(消化記録・残数はイベントを受けて口座集約側が作る)。
     */
    private function approvedLeave(User $employee, User $approver, string $date): void
    {
        $this->createWorkingDayShift($employee, $date);

        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => $date,
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');

        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();
    }

    private function createWorkingDayShift(User $user, string $date): void
    {
        $calendar = CompanyCalendar::query()->firstOrCreate(['name' => '2026年度'], ['week_starts_on' => 1]);
        if (! $calendar->years()->exists()) {
            $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        }
        $workStyle = WorkStyle::query()->firstOrCreate(['code' => 'standard-'.$user->id], [
            'name' => '通常勤務', 'work_time_system' => 'fixed',
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
}
