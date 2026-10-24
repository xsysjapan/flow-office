<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Models\AdminCommandRun;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 自動判定の実行状況と手動実行の受付
 * (`GET /paid-leave/schedule-assessment-runs/status`・`POST /paid-leave/schedule-assessment-runs`。
 * docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6・7)。
 */
class ScheduleAssessmentRunApiTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'paid-leave:assess-schedules';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => '人事 花子']);
        $this->assignRole($admin, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRun(array $attributes): AdminCommandRun
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $run = AdminCommandRun::query()->create($attributes + [
            'command_name' => self::NAME,
            'parameters' => ['trigger' => 'schedule'],
        ]);

        if ($createdAt !== null) {
            // created_atは一括代入の対象外なので、後から設定する。
            $run->forceFill(['created_at' => $createdAt])->save();
        }

        return $run;
    }

    public function test_status_without_any_run_returns_the_next_run_and_a_null_latest_run(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/paid-leave/schedule-assessment-runs/status');

        $response->assertOk();
        $response->assertJsonPath('data.latest_run', null);
        // 2026-10-21 12:00 UTC(JSTの21:00)の次の毎月1日03:00(JST)は2026-11-01 03:00 +09:00。
        $this->assertSame('2026-11-01T03:00:00+09:00', $response->json('data.next_run_at'));
    }

    public function test_next_run_at_is_computed_from_the_shared_cron_and_the_default_timezone(): void
    {
        $service = app(ScheduleAssessmentRunService::class);

        $this->assertSame('0 3 1 * *', ScheduleAssessmentRunService::SCHEDULE_CRON);
        // 2026-11-01 02:59 JST(=2026-10-31 17:59 UTC)の次は、その3:00。
        $this->assertSame('2026-11-01 03:00:00', $service->nextRunAt(Carbon::parse('2026-10-31 17:59:00', 'UTC'))->format('Y-m-d H:i:s'));
        // 3:00ちょうどの次は翌月(12/1)。
        $this->assertSame('2026-12-01 03:00:00', $service->nextRunAt(Carbon::parse('2026-10-31 18:00:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    public function test_status_of_a_running_run(): void
    {
        $admin = $this->admin();
        $this->makeRun([
            'status' => 'running',
            'parameters' => ['trigger' => 'manual'],
            'requested_by_user_id' => $admin->id,
            'started_at' => '2026-10-21 11:50:00',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/paid-leave/schedule-assessment-runs/status');

        $response->assertOk();
        $response->assertJsonPath('data.latest_run.status', 'running');
        $response->assertJsonPath('data.latest_run.trigger', 'manual');
        $response->assertJsonPath('data.latest_run.requested_by_name', '人事 花子');
        $response->assertJsonPath('data.latest_run.finished_at', null);
        $response->assertJsonPath('data.latest_run.summary', null);
        $this->assertNotNull($response->json('data.latest_run.started_at'));
    }

    public function test_status_of_a_succeeded_run_includes_the_summary(): void
    {
        $summary = ['targets' => 12, 'assessed' => 12, 'eligible' => 10, 'not_eligible' => 1, 'needs_review' => 1, 'failed' => 0];
        $this->makeRun([
            'status' => 'succeeded',
            'started_at' => '2026-10-01 03:00:00',
            'finished_at' => '2026-10-01 03:02:00',
            'summary' => $summary,
        ]);

        $response = $this->actingAs($this->admin())->getJson('/api/paid-leave/schedule-assessment-runs/status');

        $response->assertOk();
        $response->assertJsonPath('data.latest_run.status', 'succeeded');
        $response->assertJsonPath('data.latest_run.trigger', 'schedule');
        $response->assertJsonPath('data.latest_run.requested_by_name', null);
        $response->assertJsonPath('data.latest_run.error_message', null);
        $this->assertSame($summary, $response->json('data.latest_run.summary'));
        $this->assertNotNull($response->json('data.latest_run.finished_at'));
    }

    public function test_status_of_a_failed_run_includes_the_error_message(): void
    {
        $this->makeRun([
            'status' => 'failed',
            'started_at' => '2026-10-01 03:00:00',
            'finished_at' => '2026-10-01 03:02:00',
            'error_message' => '2件の判定に失敗しました',
            'summary' => ['targets' => 5, 'assessed' => 3, 'eligible' => 3, 'not_eligible' => 0, 'needs_review' => 0, 'failed' => 2],
        ]);

        $response = $this->actingAs($this->admin())->getJson('/api/paid-leave/schedule-assessment-runs/status');

        $response->assertJsonPath('data.latest_run.status', 'failed');
        $response->assertJsonPath('data.latest_run.error_message', '2件の判定に失敗しました');
        $response->assertJsonPath('data.latest_run.summary.failed', 2);
    }

    public function test_status_returns_the_most_recent_run(): void
    {
        $this->makeRun(['status' => 'succeeded', 'created_at' => '2026-09-01 03:00:00', 'started_at' => '2026-09-01 03:00:00', 'finished_at' => '2026-09-01 03:01:00']);
        $this->makeRun(['status' => 'failed', 'created_at' => '2026-10-01 03:00:00', 'started_at' => '2026-10-01 03:00:00', 'finished_at' => '2026-10-01 03:01:00']);

        $response = $this->actingAs($this->admin())->getJson('/api/paid-leave/schedule-assessment-runs/status');

        $response->assertJsonPath('data.latest_run.status', 'failed');
    }

    public function test_status_marks_a_run_stuck_in_running_for_over_two_hours_as_failed(): void
    {
        $stuck = $this->makeRun(['status' => 'running', 'started_at' => '2026-10-21 09:59:00']);
        $notYet = $this->makeRun(['status' => 'running', 'started_at' => '2026-10-21 10:01:00', 'created_at' => '2026-10-21 10:01:00']);

        $this->actingAs($this->admin())->getJson('/api/paid-leave/schedule-assessment-runs/status')->assertOk();

        $stuck->refresh();
        $this->assertSame('failed', $stuck->status);
        $this->assertSame('応答がないため失敗とみなしました', $stuck->error_message);
        $this->assertNotNull($stuck->finished_at);
        $this->assertSame('running', $notYet->refresh()->status, '2時間以内の実行中は変えない');
    }

    public function test_status_marks_a_run_stuck_in_queued_for_over_thirty_minutes_as_failed(): void
    {
        $stuck = $this->makeRun(['status' => 'queued', 'parameters' => ['trigger' => 'manual'], 'created_at' => '2026-10-21 11:29:00']);
        $recent = $this->makeRun(['status' => 'queued', 'parameters' => ['trigger' => 'manual'], 'created_at' => '2026-10-21 11:31:00']);

        $this->actingAs($this->admin())->getJson('/api/paid-leave/schedule-assessment-runs/status')->assertOk();

        $this->assertSame('failed', $stuck->refresh()->status);
        $this->assertSame('応答がないため失敗とみなしました', $stuck->error_message);
        $this->assertSame('queued', $recent->refresh()->status);
    }

    public function test_post_creates_a_queued_manual_run_and_returns_202(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/paid-leave/schedule-assessment-runs');

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.trigger', 'manual');
        $response->assertJsonPath('data.requested_by_name', '人事 花子');

        $run = AdminCommandRun::query()->sole();
        $this->assertSame(self::NAME, $run->command_name);
        $this->assertSame('queued', $run->status);
        $this->assertSame(['trigger' => 'manual'], $run->parameters);
        $this->assertSame($admin->id, $run->requested_by_user_id);
    }

    public function test_post_while_a_run_is_queued_or_running_returns_409(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/paid-leave/schedule-assessment-runs')->assertStatus(202);

        $this->actingAs($admin)->postJson('/api/paid-leave/schedule-assessment-runs')->assertStatus(409);
        $this->assertSame(1, AdminCommandRun::query()->count());

        AdminCommandRun::query()->update(['status' => 'running', 'started_at' => now()]);
        $this->actingAs($admin)->postJson('/api/paid-leave/schedule-assessment-runs')->assertStatus(409);
        $this->assertSame(1, AdminCommandRun::query()->count());
    }

    public function test_post_is_accepted_again_after_a_run_finished_or_a_stuck_run_was_failed(): void
    {
        $admin = $this->admin();
        $this->makeRun(['status' => 'succeeded', 'started_at' => '2026-10-21 11:00:00', 'finished_at' => '2026-10-21 11:01:00']);
        $this->actingAs($admin)->postJson('/api/paid-leave/schedule-assessment-runs')->assertStatus(202);

        // 固まった(2時間超のrunning)記録は、受付のときに失敗にされて、新しい記録を作れる。
        AdminCommandRun::query()->where('status', 'queued')->delete();
        $stuck = $this->makeRun(['status' => 'running', 'started_at' => '2026-10-21 09:00:00']);
        $this->actingAs($admin)->postJson('/api/paid-leave/schedule-assessment-runs')->assertStatus(202);
        $this->assertSame('failed', $stuck->refresh()->status);
    }

    public function test_users_without_leave_manage_are_forbidden(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/paid-leave/schedule-assessment-runs/status')->assertForbidden();
        $this->actingAs($employee)->postJson('/api/paid-leave/schedule-assessment-runs')->assertForbidden();
        $this->assertSame(0, AdminCommandRun::query()->count());
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/paid-leave/schedule-assessment-runs/status')->assertUnauthorized();
        $this->postJson('/api/paid-leave/schedule-assessment-runs')->assertUnauthorized();
    }

    public function test_default_timezone_falls_back_to_asia_tokyo_without_creating_a_system_setting_row(): void
    {
        DB::table('system_settings')->delete();

        $this->assertSame('Asia/Tokyo', ScheduleAssessmentRunService::defaultTimezone());
        $this->assertSame(0, DB::table('system_settings')->count());
    }

    public function test_default_timezone_reads_the_stored_system_setting(): void
    {
        SystemSetting::current()->update(['default_timezone' => 'UTC']);

        $this->assertSame('UTC', ScheduleAssessmentRunService::defaultTimezone());
    }
}
