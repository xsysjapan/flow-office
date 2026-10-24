<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Console\AdminCommandRegistry;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;
use App\Domain\PaidLeaveSchedule\Support\PaidLeaveScheduleAssessmentPlan;
use App\Domain\PaidLeaveSchedule\Support\PaidLeaveScheduleAssessmentPlanner;
use App\Models\AdminCommandRun;
use App\Models\LeaveAttendanceRateDay;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleAssessment;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `paid-leave:assess-schedules`(毎月1日と手動の自動判定。
 * docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6)。
 * 来た「予定」だけを判定する・1社員の失敗で止めない・記録(admin_command_runs)の更新・
 * 「今日」のタイムゾーン・手動の記録を拾う経路を、イベントと読み取りビューの状態で確かめる。
 */
class AssessPaidLeaveSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 全社ルールを、閾値80%・初回6か月・判定周期12か月の1件にする。
        PaidLeaveGrantRule::query()->update(['is_active' => false]);
        PaidLeaveGrantRule::query()->create([
            'name' => 'テスト全社ルール',
            'work_style_id' => null,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2?: float}>  $rows  [付与予定日, 名目の月数, 日数]
     * @return array<int, string> 作った行のID
     */
    private function createEntries(User $user, array $rows): array
    {
        $ids = [];
        $candidates = [];
        foreach ($rows as [$scheduledOn, $months, $days]) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $candidates[] = ['entryId' => $id, 'scheduledOn' => $scheduledOn, 'category' => 'normal', 'candidateGrantDays' => $days ?? 10.0, 'nominalServiceMonths' => $months];
        }

        app(CommandBus::class)->dispatch(new EnsureFutureScheduleGenerated(userId: $user->id, candidates: $candidates));

        return $ids;
    }

    private function workingDays(User $user, string $from, int $count, int $attended): void
    {
        $date = Carbon::parse($from);
        for ($i = 0; $i < $count; $i++, $date->addDay()) {
            LeaveAttendanceRateDay::query()->create([
                'user_id' => $user->id,
                'work_date' => $date->toDateString(),
                'is_working_day' => true,
                'attended' => $i < $attended,
                'full_leave_kinds' => [],
                'partial_leave_kinds' => [],
            ]);
        }
    }

    private function statusOf(string $entryId): string
    {
        return PaidLeaveScheduleEntry::query()->findOrFail($entryId)->status;
    }

    public function test_it_assesses_only_scheduled_entries_whose_date_has_come(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01', 'usage_start_date' => null]);
        [$past, $today, $future] = $this->createEntries($user, [
            ['2026-04-01', 6, 10.0],
            ['2026-10-21', 18, 11.0],
            ['2026-10-22', 30, 12.0],
        ]);
        // 初回(2026-04-01)の判定期間2025-10-01〜2026-03-31に、所定100日・出勤100日。
        $this->workingDays($user, '2025-10-01', 100, 100);

        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($past));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_NEEDS_REVIEW, $this->statusOf($today), '当日の行も対象(所定労働日が無いので要確認)');
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($future), '未来の行は予定のまま');

        $assessment = PaidLeaveScheduleAssessment::query()->where('schedule_entry_id', $past)->sole();
        $this->assertSame('v2', $assessment->policy_version);
        $this->assertSame(0, $assessment->deemed_attendance_days);
        $this->assertSame('2025-10-01', $assessment->period_start->toDateString());
        $this->assertSame('2026-03-31', $assessment->period_end->toDateString());
        $this->assertSame(0, PaidLeaveScheduleAssessment::query()->where('schedule_entry_id', $future)->count());

        $run = AdminCommandRun::query()->sole();
        $this->assertSame('paid-leave:assess-schedules', $run->command_name);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(['trigger' => 'schedule'], $run->parameters);
        $this->assertNull($run->requested_by_user_id);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error_message);
        $this->assertSame(
            ['targets' => 2, 'assessed' => 2, 'eligible' => 1, 'not_eligible' => 0, 'needs_review' => 1, 'failed' => 0],
            $run->summary,
        );
    }

    public function test_an_already_assessed_entry_is_not_assessed_again(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$entryId] = $this->createEntries($user, [['2026-04-01', 6, 10.0]]);

        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);
        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);

        $this->assertSame(1, PaidLeaveScheduleAssessment::query()->where('schedule_entry_id', $entryId)->count());
        $this->assertSame(2, AdminCommandRun::query()->count());
        // 2回目は「予定」の行が残っていないので、対象は0件。
        $this->assertSame(0, AdminCommandRun::query()->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($run) => $run->summary['targets'])->min());
    }

    public function test_a_bundled_pair_is_assessed_independently_and_the_second_is_eligible_even_if_the_first_is_not(): void
    {
        Carbon::setTestNow('2026-04-02 12:00:00');
        $user = User::factory()->create(['hire_date' => '2026-01-10']);
        [$first, $second] = $this->createEntries($user, [['2026-04-01', 6, 10.0], ['2026-04-01', 18, 11.0]]);
        // 所定55日・出勤10日。みなし68日を加えても(10+68)/(55+68)=63.4%で対象外。
        $this->workingDays($user, '2026-01-10', 55, 10);

        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_NOT_ELIGIBLE, $this->statusOf($first));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($second));

        $firstAssessment = PaidLeaveScheduleAssessment::query()->where('schedule_entry_id', $first)->sole();
        $this->assertSame(68, $firstAssessment->deemed_attendance_days);
        $this->assertSame(55, $firstAssessment->denominator_days);
        $this->assertSame(10, $firstAssessment->attendance_days);
        $this->assertEqualsWithDelta(63.41, $firstAssessment->attendance_rate, 0.01);

        $summary = AdminCommandRun::query()->sole()->summary;
        $this->assertSame(1, $summary['eligible']);
        $this->assertSame(1, $summary['not_eligible']);
    }

    public function test_one_users_failure_does_not_stop_the_others_and_the_exit_code_is_1(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $failing = User::factory()->create(['hire_date' => '2025-10-01', 'name' => '失敗する社員']);
        $ok = User::factory()->create(['hire_date' => '2025-10-01', 'name' => '成功する社員']);
        [$failingEntry] = $this->createEntries($failing, [['2026-04-01', 6, 10.0]]);
        [$okEntry] = $this->createEntries($ok, [['2026-04-01', 6, 10.0]]);
        $this->workingDays($ok, '2025-10-01', 100, 100);

        // 失敗する社員の判定だけで例外を出すPlanner。
        $real = app(PaidLeaveScheduleAssessmentPlanner::class);
        $failingUserId = $failing->id;
        $this->app->instance(PaidLeaveScheduleAssessmentPlanner::class, new class($real, $failingUserId) extends PaidLeaveScheduleAssessmentPlanner
        {
            public function __construct(private PaidLeaveScheduleAssessmentPlanner $real, private string $failingUserId) {}

            public function plan(PaidLeaveScheduleEntry $entry, User $user): PaidLeaveScheduleAssessmentPlan
            {
                if ($user->id === $this->failingUserId) {
                    throw new \RuntimeException('判定の材料を読めません');
                }

                return $this->real->plan($entry, $user);
            }
        });

        $this->artisan('paid-leave:assess-schedules')
            ->expectsOutputToContain("社員 {$failingUserId}: 判定の材料を読めません")
            ->assertExitCode(1);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($failingEntry));
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $this->statusOf($okEntry));

        $run = AdminCommandRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('1件の判定に失敗しました', $run->error_message);
        $this->assertSame(1, $run->exit_code);
        $this->assertSame(
            ['targets' => 2, 'assessed' => 1, 'eligible' => 1, 'not_eligible' => 0, 'needs_review' => 0, 'failed' => 1],
            $run->summary,
        );
        $this->assertStringContainsString($failingUserId, $run->output);
    }

    public function test_the_boundary_uses_the_default_timezone_date_not_utc(): void
    {
        // UTCでは2026-10-20 16:00(前日)、Asia/Tokyoでは2026-10-21 01:00(当日)。
        Carbon::setTestNow('2026-10-20 16:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$todayJst, $tomorrowJst] = $this->createEntries($user, [['2026-10-21', 6, 10.0], ['2026-10-22', 18, 11.0]]);

        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);

        $this->assertNotSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($todayJst), 'JSTの当日の行は対象');
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($tomorrowJst));
    }

    public function test_the_boundary_follows_the_configured_timezone(): void
    {
        SystemSetting::current()->update(['default_timezone' => 'UTC']);
        Carbon::setTestNow('2026-10-20 16:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$entryId] = $this->createEntries($user, [['2026-10-21', 6, 10.0]]);

        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);

        // タイムゾーンがUTCなら2026-10-20なので、2026-10-21の行は対象外。
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($entryId));
        $this->assertSame(0, AdminCommandRun::query()->sole()->summary['targets']);
    }

    public function test_a_scheduled_run_does_nothing_while_another_run_is_active(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$entryId] = $this->createEntries($user, [['2026-04-01', 6, 10.0]]);
        AdminCommandRun::query()->create([
            'command_name' => 'paid-leave:assess-schedules',
            'parameters' => ['trigger' => 'manual'],
            'status' => 'running',
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('paid-leave:assess-schedules')->assertExitCode(0);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($entryId));
        $this->assertSame(1, AdminCommandRun::query()->count(), '記録を作らずに終える');
    }

    public function test_pending_picks_up_a_queued_manual_run_and_executes_it(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $admin = User::factory()->create();
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$entryId] = $this->createEntries($user, [['2026-04-01', 6, 10.0]]);
        $run = AdminCommandRun::query()->create([
            'command_name' => 'paid-leave:assess-schedules',
            'parameters' => ['trigger' => 'manual'],
            'status' => 'queued',
            'requested_by_user_id' => $admin->id,
        ]);

        $this->artisan('paid-leave:assess-schedules --pending')->assertExitCode(0);

        $this->assertNotSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($entryId));
        $run->refresh();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame($admin->id, $run->requested_by_user_id);
        $this->assertSame(['trigger' => 'manual'], $run->parameters);
        $this->assertSame(1, $run->summary['targets']);
        $this->assertSame(1, AdminCommandRun::query()->count(), '新しい記録は作らない');
    }

    public function test_pending_does_nothing_without_a_queued_run(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$entryId] = $this->createEntries($user, [['2026-04-01', 6, 10.0]]);

        $this->artisan('paid-leave:assess-schedules --pending')->assertExitCode(0);

        $this->assertSame(PaidLeaveScheduleEntry::STATUS_SCHEDULED, $this->statusOf($entryId));
        $this->assertSame(0, AdminCommandRun::query()->count());
    }

    public function test_pending_does_not_start_while_a_run_is_running(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        $this->createEntries($user, [['2026-04-01', 6, 10.0]]);
        $running = AdminCommandRun::query()->create([
            'command_name' => 'paid-leave:assess-schedules', 'parameters' => ['trigger' => 'schedule'],
            'status' => 'running', 'started_at' => now()->subMinutes(1),
        ]);
        $queued = AdminCommandRun::query()->create([
            'command_name' => 'paid-leave:assess-schedules', 'parameters' => ['trigger' => 'manual'], 'status' => 'queued',
        ]);

        $this->artisan('paid-leave:assess-schedules --pending')->assertExitCode(0);

        $this->assertSame('running', $running->refresh()->status);
        $this->assertSame('queued', $queued->refresh()->status);
    }

    public function test_pending_marks_a_stuck_running_run_as_failed_and_then_runs_the_queued_one(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        $this->createEntries($user, [['2026-04-01', 6, 10.0]]);
        $stuck = AdminCommandRun::query()->create([
            'command_name' => 'paid-leave:assess-schedules', 'parameters' => ['trigger' => 'schedule'],
            'status' => 'running', 'started_at' => now()->subMinutes(121),
        ]);
        $queued = AdminCommandRun::query()->create([
            'command_name' => 'paid-leave:assess-schedules', 'parameters' => ['trigger' => 'manual'], 'status' => 'queued',
        ]);

        $this->artisan('paid-leave:assess-schedules --pending')->assertExitCode(0);

        $stuck->refresh();
        $this->assertSame('failed', $stuck->status);
        $this->assertSame(ScheduleAssessmentRunService::STALE_MESSAGE, $stuck->error_message);
        $this->assertNotNull($stuck->finished_at);
        $this->assertSame('succeeded', $queued->refresh()->status);
    }

    public function test_it_rejects_an_unknown_trigger(): void
    {
        $this->artisan('paid-leave:assess-schedules --trigger=bogus')->assertExitCode(1);

        $this->assertSame(0, AdminCommandRun::query()->count());
    }

    public function test_the_command_is_not_exposed_to_the_admin_command_screen(): void
    {
        $registry = app(AdminCommandRegistry::class);

        $this->assertNull($registry->find('paid-leave:assess-schedules'));
    }

    public function test_old_assessment_event_without_deemed_days_is_read_as_zero(): void
    {
        $user = User::factory()->create(['hire_date' => '2025-10-01']);
        [$entryId] = $this->createEntries($user, [['2026-04-01', 6, 10.0]]);

        // deemedAttendanceDays導入前のイベント(ペイロードにdeemedAttendanceDaysが無い)を直接保存して、集約を再構築する。
        $streamId = PaidLeaveScheduleAggregate::streamIdFor($user->id);
        $version = (int) DB::table('stored_events')->where('aggregate_uuid', $streamId)->max('aggregate_version');
        $payload = [
            'userId' => $user->id, 'scheduleEntryId' => $entryId, 'assessmentId' => (string) Str::uuid(),
            'periodStart' => '2025-10-01', 'periodEnd' => '2026-03-31', 'denominatorDays' => 10, 'attendanceDays' => 9,
            'excludedDays' => 0, 'attendanceRate' => 90.0, 'policyVersion' => 'v1', 'automaticResult' => 'Eligible',
        ];
        $template = (array) DB::table('stored_events')->where('aggregate_uuid', $streamId)->first();
        unset($template['id']);
        $template['aggregate_version'] = $version + 1;
        $template['event_class'] = 'paid_leave_schedule.assessment_recorded';
        $template['event_properties'] = json_encode($payload);
        DB::table('stored_events')->insert($template);

        $entry = PaidLeaveScheduleAggregate::retrieve($streamId)->forUser($user->id)->entry($entryId);

        $this->assertSame(0, $entry['assessments'][$payload['assessmentId']]['deemedAttendanceDays']);
        $this->assertSame(PaidLeaveScheduleEntry::STATUS_ELIGIBLE, $entry['status']);
    }
}
