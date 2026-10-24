<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use App\Support\BusinessDate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 付与予定の文脈の「今日」は、既定のタイムゾーン(`system_settings.default_timezone`)の日付
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 段階2)。
 * 自動判定のスケジュール定義(`routes/console.php`)も確かめる。
 */
class PaidLeaveBusinessDateScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_business_date_is_the_default_timezone_date_at_midnight(): void
    {
        // UTCでは2026-10-20 16:00、Asia/Tokyoでは2026-10-21 01:00。
        Carbon::setTestNow('2026-10-20 16:00:00');

        $today = BusinessDate::today();

        $this->assertSame('2026-10-21 00:00:00', $today->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $today->getTimezone()->getName(), '付与予定の日付(UTCの0時)とそのまま比較できる');
    }

    public function test_business_date_follows_the_configured_timezone(): void
    {
        SystemSetting::current()->update(['default_timezone' => 'UTC']);
        Carbon::setTestNow('2026-10-20 16:00:00');

        $this->assertSame('2026-10-20', BusinessDate::today()->toDateString());
    }

    public function test_roll_schedules_does_not_touch_the_row_dated_the_default_timezone_today(): void
    {
        // UTCでは2026-10-20 16:00。Asia/Tokyoの今日は2026-10-21なので、再計算の対象は明日(10-22)以降。
        // UTCの日付で動くと、10-21の行が再計算の対象になり、候補に無いので取り消される。
        Carbon::setTestNow('2026-10-20 16:00:00');
        PaidLeaveGrantRule::query()->update(['is_active' => false]);
        PaidLeaveGrantRule::query()->create([
            'name' => 'テスト全社ルール', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, 'is_active' => true,
        ]);
        $user = User::factory()->create(['hire_date' => '2024-04-01', 'usage_start_date' => '2024-04-01']);
        $entryId = (string) Str::uuid();
        app(CommandBus::class)->dispatch(new EnsureFutureScheduleGenerated(userId: $user->id, candidates: [
            ['entryId' => $entryId, 'scheduledOn' => '2026-10-21', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 30],
        ]));

        $this->artisan('paid-leave:roll-schedules')->assertExitCode(0);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, PaidLeaveScheduleEntry::query()->findOrFail($entryId)->status);
    }

    public function test_current_work_style_uses_the_default_timezone_month(): void
    {
        // UTCでは2026-09-30 16:00(9月)、Asia/Tokyoでは2026-10-01 01:00(10月)。10月の割当が有効。
        Carbon::setTestNow('2026-09-30 16:00:00');
        $user = User::factory()->create();
        $old = $this->workStyle('OLD');
        $new = $this->workStyle('NEW');
        foreach (['2026-09' => $old, '2026-10' => $new] as $yearMonth => $workStyle) {
            UserWorkStyleMonthlyAssignment::query()->create([
                'user_id' => $user->id, 'year_month' => $yearMonth, 'work_style_id' => $workStyle->id, 'assigned_by_user_id' => $user->id,
            ]);
        }
        $generator = app(ScheduleCandidateGenerator::class);

        $this->assertSame($new->id, $generator->currentWorkStyleFor($user)?->id);
    }

    public function test_reapply_job_uses_the_default_timezone_today_as_the_start_date(): void
    {
        Carbon::setTestNow('2026-10-20 16:00:00');
        PaidLeaveGrantRule::query()->update(['is_active' => false]);
        PaidLeaveGrantRule::query()->create([
            'name' => 'テスト全社ルール', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, 'is_active' => true,
        ]);
        $user = User::factory()->create(['hire_date' => '2024-04-01', 'usage_start_date' => '2024-04-01', 'employment_status' => 'active']);
        $past = (string) Str::uuid();
        $today = (string) Str::uuid();
        app(CommandBus::class)->dispatch(new EnsureFutureScheduleGenerated(userId: $user->id, candidates: [
            ['entryId' => $past, 'scheduledOn' => '2026-10-20', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 30],
            ['entryId' => $today, 'scheduledOn' => '2026-10-21', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 42],
        ]));

        (new ReapplyPaidLeaveSchedulePolicyJob('テスト'))->reapplyAll(app(CommandBus::class), app(ScheduleCandidateGenerator::class));

        // 再作成の起点は、Asia/Tokyoの今日(2026-10-21)。それより前の行(10-20)は触らず、当日(10-21)の行は候補に無いので取り消される。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, PaidLeaveScheduleEntry::query()->findOrFail($past)->status);
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_CANCELLED, PaidLeaveScheduleEntry::query()->findOrFail($today)->status);
    }

    public function test_the_assessment_schedules_are_defined_with_the_shared_cron_and_default_timezone(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events());

        $monthly = $events->first(fn ($event) => str_contains($event->command, 'paid-leave:assess-schedules --trigger=schedule'));
        $this->assertNotNull($monthly);
        $this->assertSame(ScheduleAssessmentRunService::SCHEDULE_CRON, $monthly->expression);
        $this->assertSame('0 3 1 * *', $monthly->expression);
        $this->assertSame('Asia/Tokyo', (string) $monthly->timezone);
        $this->assertTrue($monthly->withoutOverlapping);
        $this->assertSame(180, $monthly->expiresAt);
        $this->assertTrue($monthly->runInBackground);

        $pending = $events->first(fn ($event) => str_contains($event->command, 'paid-leave:assess-schedules --pending'));
        $this->assertNotNull($pending);
        $this->assertSame('* * * * *', $pending->expression);
        $this->assertTrue($pending->withoutOverlapping);
        $this->assertSame(180, $pending->expiresAt);
        $this->assertTrue($pending->runInBackground);
    }

    private function workStyle(string $code): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => $code,
            'name' => $code,
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }
}
