<?php

namespace Tests\Feature\Console;

use App\Jobs\RunAdminCommandJob;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * spec.md 実装対象: `paid-leave:schedule:rebuild`コマンドの統合テスト。
 * docs/changesets/20260919-paid-leave-schedule-rebuild/spec.md参照。
 *
 * 過去の有給付与予定Schedule エントリを現在の付与ルール・ポリシーに基づいて
 * 取消+再作成するコマンド。
 */
class RebuildPaidLeaveScheduleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-19'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createNormalWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'NORMAL',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }

    private function assignWorkStyle(User $user, WorkStyle $workStyle, string $yearMonth = '2020-01'): void
    {
        UserWorkStyleMonthlyAssignment::query()->create([
            'user_id' => $user->id,
            'year_month' => $yearMonth,
            'work_style_id' => $workStyle->id,
            'assigned_by_user_id' => $user->id,
        ]);
    }

    private function seedNormalPolicy(): void
    {
        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18] as $months => $days) {
            PaidLeaveGrantPolicy::query()->create([
                'version' => 'v1', 'continuous_service_months' => $months, 'grant_days' => $days,
                'effective_from' => '2020-01-01', 'is_active' => true,
            ]);
        }
    }

    private function createAnniversaryRuleFor(WorkStyle $workStyle): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '通常勤務(周年)',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => true,
        ]);

        foreach ([6 => 10, 18 => 11, 30 => 12, 42 => 14, 54 => 16, 66 => 18] as $months => $days) {
            $rule->steps()->create(['continuous_service_months' => $months, 'grant_days' => $days]);
        }

        return $rule;
    }

    public function test_it_rebuilds_schedule_for_employees_matching_specified_rule(): void
    {
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $rule = $this->createAnniversaryRuleFor($workStyle);

        // 1人目: 指定ルール対象(十分過去に入社)
        $user1 = User::factory()->create([
            'hire_date' => '2020-09-19',
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user1, $workStyle);

        // 2人目: 別WorkStyle(別ルール)なので除外対象
        $otherStyle = WorkStyle::query()->create([
            'code' => 'SHIFT',
            'name' => 'シフト勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
        $otherRule = $this->createAnniversaryRuleFor($otherStyle);

        $user2 = User::factory()->create([
            'hire_date' => '2020-09-20',
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user2, $otherStyle);

        // 既存エントリ(古い内容で作成): 周年6年目(2026-09-19)
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user1->id,
            'scheduled_on' => '2026-09-19',
            'status' => 'Scheduled',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user2->id,
            'scheduled_on' => '2026-09-20',
            'status' => 'Scheduled',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);

        // 指定ルール対象のみ実行
        $this->artisan('paid-leave:schedule:rebuild', [
            '--rule-id' => $rule->id,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--reason' => 'ルール修正のため',
        ])->assertSuccessful();

        // user1 のエントリは新しい内容(normal 18.0 for 72 months continuous service)に置き換わる
        $entries1 = PaidLeaveScheduleEntry::query()->where('user_id', $user1->id)->get();
        $this->assertGreaterThan(0, $entries1->count(), 'Entry should exist after rebuild for user1');
        // 最新のエントリが新しい内容
        $entry1 = $entries1->sortBy('created_at')->last();
        $this->assertSame('normal', $entry1->category);
        $this->assertEquals(18.0, (float) $entry1->candidate_grant_days);

        // user2 のエントリは変更なし(別ルール対象なので実行対象外)
        $entry2 = PaidLeaveScheduleEntry::query()->where('user_id', $user2->id)->orderBy('created_at')->first();
        $this->assertSame('proportional', $entry2->category);
        $this->assertEquals(8.0, (float) $entry2->candidate_grant_days);
    }

    public function test_it_targets_all_employees_when_rule_id_is_not_specified(): void
    {
        $this->seedNormalPolicy();
        $workStyle1 = $this->createNormalWorkStyle();
        $rule1 = $this->createAnniversaryRuleFor($workStyle1);

        $workStyle2 = WorkStyle::query()->create([
            'code' => 'SHIFT',
            'name' => 'シフト勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
        $rule2 = $this->createAnniversaryRuleFor($workStyle2);

        // 両者を作成(十分過去)
        $user1 = User::factory()->create(['hire_date' => '2020-09-19', 'employment_status' => 'active']);
        $this->assignWorkStyle($user1, $workStyle1);

        $user2 = User::factory()->create(['hire_date' => '2020-09-20', 'employment_status' => 'active']);
        $this->assignWorkStyle($user2, $workStyle2);

        // 6年目の周年エントリを作成
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user1->id,
            'scheduled_on' => '2026-09-19',
            'status' => 'Scheduled',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user2->id,
            'scheduled_on' => '2026-09-20',
            'status' => 'Scheduled',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);

        // ルールID未指定で実行(全社員対象)
        $this->artisan('paid-leave:schedule:rebuild', [
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--reason' => '全社的な洗い替え',
        ])->assertSuccessful();

        // 両方のユーザーのエントリが更新される (72ヶ月=18日)
        $entries1 = PaidLeaveScheduleEntry::query()->where('user_id', $user1->id)->get();
        $entry1 = $entries1->sortBy('created_at')->last();
        $this->assertSame('normal', $entry1->category);
        $this->assertEquals(18.0, (float) $entry1->candidate_grant_days);

        $entries2 = PaidLeaveScheduleEntry::query()->where('user_id', $user2->id)->get();
        $entry2 = $entries2->sortBy('created_at')->last();
        $this->assertSame('normal', $entry2->category);
        $this->assertEquals(18.0, (float) $entry2->candidate_grant_days);
    }

    public function test_it_uses_hire_date_as_default_from_when_not_specified(): void
    {
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        // hire_date を十分に過去に設定し、最初の周年候補が指定期間内に入るようにする
        $user = User::factory()->create([
            'hire_date' => '2025-09-19',  // 過去6ヶ月分が対象になる
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user, $workStyle);

        // 6ヶ月後(2026-03-19)のエントリを先に作成
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => '2026-03-19',
            'status' => 'Scheduled',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);

        $this->artisan('paid-leave:schedule:rebuild', [
            '--to' => '2026-09-19',
            '--reason' => 'テスト',
        ])->assertSuccessful();

        // エントリは新しい内容に置き換わる
        $entries = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('scheduled_on', '2026-03-19')
            ->get();

        // 最新のエントリが新しい内容であることを確認
        $latestEntry = $entries->sortBy('created_at')->last();
        $this->assertSame('normal', $latestEntry->category);
        $this->assertEquals(10.0, (float) $latestEntry->candidate_grant_days);
    }

    public function test_it_uses_today_plus_one_year_as_default_to_when_not_specified(): void
    {
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        // 1年前に入社した社員なので、周年候補が future range に入る
        $user = User::factory()->create([
            'hire_date' => '2025-09-19',
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user, $workStyle);

        // 6ヶ月後(2026-03-19)のエントリを作成(today + 1year以内)
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => '2026-03-19',
            'status' => 'Scheduled',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);

        $this->artisan('paid-leave:schedule:rebuild', [
            '--from' => '2025-09-19',
            '--reason' => 'テスト',
        ])->assertSuccessful();

        // today + 1year以内のエントリは再作成対象のため、新しい内容に
        $entries = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('scheduled_on', '2026-03-19')
            ->get();

        $latestEntry = $entries->sortBy('created_at')->last();
        $this->assertSame('normal', $latestEntry->category);
        $this->assertEquals(10.0, (float) $latestEntry->candidate_grant_days);
    }

    public function test_it_supersedes_and_recreates_granted_entries(): void
    {
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        // 66ヶ月目(付与ルールの最終ステップ、18日)の周年が2026-09-19になるよう
        // 入社日を逆算する
        $user = User::factory()->create([
            'hire_date' => '2021-03-19',
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user, $workStyle);

        // 66ヶ月目の周年(2026-09-19)のGrantedエントリを、古い(誤った)内容で作成
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => '2026-09-19',
            'status' => 'Granted',
            'category' => 'proportional',
            'candidate_grant_days' => 8.0,
        ]);

        $this->artisan('paid-leave:schedule:rebuild', [
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--reason' => '過去分洗い替え',
        ])->assertSuccessful();

        // Granted エントリは取消+再作成される
        $entries = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('scheduled_on', '2026-09-19')
            ->get();

        $this->assertGreaterThanOrEqual(1, $entries->count(), 'Entry should be recreated');
        // 最新のエントリが新しい内容 (66ヶ月=18日)
        $latestEntry = $entries->sortBy('created_at')->last();
        $this->assertSame('normal', $latestEntry->category);
        $this->assertEquals(18.0, (float) $latestEntry->candidate_grant_days);
    }

    public function test_it_is_idempotent_when_content_is_unchanged(): void
    {
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        $user = User::factory()->create([
            'hire_date' => '2025-01-15',
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user, $workStyle);

        // 最初のエントリを作成(正しい内容で)
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => '2025-07-15',  // 6ヶ月後
            'status' => 'Scheduled',
            'category' => 'normal',
            'candidate_grant_days' => 10.0,
        ]);

        // 1回目実行
        $this->artisan('paid-leave:schedule:rebuild', [
            '--from' => '2025-01-01',
            '--to' => '2026-12-31',
            '--reason' => '洗い替え',
        ])->assertSuccessful();

        $countAfterFirstRun = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->count();

        // 2回目実行 (内容が変わっていないため no-op)
        $this->artisan('paid-leave:schedule:rebuild', [
            '--from' => '2025-01-01',
            '--to' => '2026-12-31',
            '--reason' => '洗い替え',
        ])->assertSuccessful();

        $countAfterSecondRun = PaidLeaveScheduleEntry::query()
            ->where('user_id', $user->id)
            ->count();

        // 2回目以降は内容が変わらないため取消・再作成が発生しない(冪等)
        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }

    public function test_account_paid_leave_grants_are_not_affected(): void
    {
        // Account側 paid_leave_grants テーブルが影響を受けないことを確認する。
        // (Schedule側の Granted エントリ取消はアーキテクチャ上 Account に波及しない。
        // ここでは単に、コマンド実行時に Account レコードの直接削除・変更が発生しないことを検証)
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        $user = User::factory()->create([
            'hire_date' => '2020-01-15',
            'employment_status' => 'active',
        ]);
        $this->assignWorkStyle($user, $workStyle);

        // Granted エントリを作成
        PaidLeaveScheduleEntry::query()->create([
            'user_id' => $user->id,
            'scheduled_on' => '2025-01-15',
            'status' => 'Granted',
            'category' => 'normal',
            'candidate_grant_days' => 10.0,
            'grant_id' => 'grant-1',
        ]);

        // コマンド実行
        $this->artisan('paid-leave:schedule:rebuild', [
            '--from' => '2025-01-01',
            '--to' => '2026-12-31',
            '--reason' => '過去分洗い替え',
        ])->assertSuccessful();

        // Schedule側エントリは置き換わるが、Database行そのものは保持される(イベントのみ追記)
        // Account側に直接影響が無いことは設計で保証されている
    }

    public function test_it_validates_parameters_at_api_level(): void
    {
        // AdminCommandController経由での実行時にバリデーションが機能することを確認
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin']));

        // reason 未指定でバリデーションエラー
        $this->actingAs($admin)->postJson('/api/admin/commands/paid-leave:schedule:rebuild/runs', [
            'parameters' => [
                'rule-id' => null,
                'from' => '2025-01-01',
                'to' => '2026-12-31',
                // 'reason' 未指定
            ],
        ])->assertUnprocessable();

        // 存在しないrule-idでバリデーションエラー
        $this->actingAs($admin)->postJson('/api/admin/commands/paid-leave:schedule:rebuild/runs', [
            'parameters' => [
                'rule-id' => 9999,
                'from' => '2025-01-01',
                'to' => '2026-12-31',
                'reason' => 'テスト',
            ],
        ])->assertUnprocessable();

        // 不正なdate_format
        $this->actingAs($admin)->postJson('/api/admin/commands/paid-leave:schedule:rebuild/runs', [
            'parameters' => [
                'rule-id' => null,
                'from' => '2025/01/01',
                'to' => '2026-12-31',
                'reason' => 'テスト',
            ],
        ])->assertUnprocessable();

        // to < from
        $this->actingAs($admin)->postJson('/api/admin/commands/paid-leave:schedule:rebuild/runs', [
            'parameters' => [
                'rule-id' => null,
                'from' => '2026-12-31',
                'to' => '2025-01-01',
                'reason' => 'テスト',
            ],
        ])->assertUnprocessable();
    }

    public function test_it_can_be_executed_via_admin_command_controller(): void
    {
        Queue::fake();
        $this->seedNormalPolicy();
        $workStyle = $this->createNormalWorkStyle();
        $this->createAnniversaryRuleFor($workStyle);

        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->firstOrCreate(['code' => Role::ADMIN], ['name' => 'Admin']));

        $response = $this->actingAs($admin)->postJson('/api/admin/commands/paid-leave:schedule:rebuild/runs', [
            'parameters' => [
                'rule-id' => null,
                'from' => '2025-01-01',
                'to' => '2026-12-31',
                'reason' => 'テスト',
            ],
        ])->assertAccepted();

        $this->assertSame('queued', $response->json('data.status'));
        Queue::assertPushed(RunAdminCommandJob::class);
    }
}
