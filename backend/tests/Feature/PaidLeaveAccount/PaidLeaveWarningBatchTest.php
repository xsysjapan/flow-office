<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use App\Models\AttendanceDay;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\User;
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
 */
class PaidLeaveWarningBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_warn_expiring_notifies_and_marks_grants_within_the_warning_window(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        $expiringSoon = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2024-08-10', 'expires_on' => '2026-10-01',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
        ]);
        $expiringLater = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-08-10', 'expires_on' => '2028-08-10',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave($today->toDateString()));

        $this->assertSame(1, $count);
        $this->assertNotNull($expiringSoon->refresh()->expiry_warned_at);
        $this->assertNull($expiringLater->refresh()->expiry_warned_at);
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

        // Tokyoの「今日」(8/10)からはすでに過ぎているため対象外になるが、
        // LAの「今日」(8/9)からはちょうど当日(下限境界)のため対象になる。
        $tokyoGrant = PaidLeaveGrant::query()->create([
            'user_id' => $tokyoUser->id, 'granted_on' => '2024-08-10', 'expires_on' => '2026-08-09',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
        ]);
        $laGrant = PaidLeaveGrant::query()->create([
            'user_id' => $laUser->id, 'granted_on' => '2024-08-10', 'expires_on' => '2026-08-09',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
        ]);

        $this->travelTo(Carbon::parse('2026-08-10 03:00:00', 'UTC'));
        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave);

        $this->assertSame(1, $count);
        $this->assertNull($tokyoGrant->refresh()->expiry_warned_at);
        $this->assertNotNull($laGrant->refresh()->expiry_warned_at);
    }

    public function test_warn_expiring_does_not_renotify_an_already_warned_grant(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2024-08-10', 'expires_on' => '2026-10-01',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
            'expiry_warned_at' => Carbon::parse('2026-08-01'),
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnExpiringPaidLeave($today->toDateString()));

        $this->assertSame(0, $count);
    }

    private function createUsage(User $employee, PaidLeaveGrant $grant, float $days, string $date): PaidLeaveUsage
    {
        $day = AttendanceDay::query()->create([
            'user_id' => $employee->id, 'work_date' => $date, 'status' => 'clocked_out', 'source' => 'manual',
        ]);
        $request = PaidLeaveRequest::query()->create([
            'user_id' => $employee->id, 'approver_user_id' => $employee->id, 'status' => 'approved',
            'leave_type' => 'full', 'target_date' => $date, 'requested_days' => $days,
        ]);

        return PaidLeaveUsage::query()->create([
            'user_id' => $employee->id, 'attendance_day_id' => $day->id,
            'paid_leave_grant_id' => $grant->id, 'paid_leave_request_id' => $request->id,
            'used_on' => $date, 'used_days' => $days, 'usage_type' => 'full',
        ]);
    }

    public function test_warn_five_day_obligation_warns_when_usage_is_insufficient_near_the_deadline(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-09-01', 'expires_on' => '2027-08-31',
            'granted_days' => 10, 'used_days' => 2, 'remaining_days' => 8,
        ]);
        $this->createUsage($employee, $grant, 2, '2026-06-01');

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(1, $count);
        $this->assertNotNull($grant->refresh()->five_day_obligation_warned_at);
    }

    public function test_warn_five_day_obligation_uses_calendar_days_across_user_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 00:00:00', 'Asia/Tokyo'));
        $employee = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id,
            'granted_on' => '2025-10-08',
            'expires_on' => '2027-10-08',
            'granted_days' => 10,
            'used_days' => 0,
            'remaining_days' => 10,
        ]);

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

        $grant = PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-09-01', 'expires_on' => '2027-08-31',
            'granted_days' => 10, 'used_days' => 5, 'remaining_days' => 5,
        ]);
        $this->createUsage($employee, $grant, 5, '2026-06-01');

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(0, $count);
    }

    public function test_warn_five_day_obligation_skips_grants_outside_the_warning_window(): void
    {
        $today = Carbon::parse('2026-08-10');
        $employee = User::factory()->create();

        // 義務期限(付与日+1年)が2027-08-31で、警告ウィンドウ(60日前)にまだ入っていない
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2026-08-31', 'expires_on' => '2028-08-31',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $count = app(CommandBus::class)->dispatch(new WarnFiveDayObligation($today->toDateString()));

        $this->assertSame(0, $count);
    }
}
