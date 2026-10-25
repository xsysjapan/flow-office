<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * ReadModelの作り直し(全テーブルを1トランザクションで空にして全Projectorをreplay)のシナリオテスト
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md)。
 * `RefreshDatabase`の外側のトランザクションの中ではSQLiteの`PRAGMA foreign_keys`を切り替えられず、親テーブルのDELETEが
 * 外部キー違反になるため、`DatabaseMigrations`を使う。
 * 前提データはCommand経由で作る。「壊れたReadModel」を作るときだけ`DB::table()->update`を使う。
 */
class ReadModelRebuilderTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    /**
     * `DatabaseMigrations`の`migrate:rollback`は、SQLiteで`down()`が失敗する既存のマイグレーションがあるため使わない
     * (代わりの後始末は`runRebuildDatabaseMigrations`)。
     */
    public function runDatabaseMigrations()
    {
        $this->runRebuildDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRebuildHeartbeat();
        config(['read_model_rebuild.claim_wait_seconds' => 0]);
        $this->seedAccessControl();
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    /** @return array{0: User, 1: string} 利用者と、取消済みの付与のID */
    private function userWithRevokedGrant(User $admin): array
    {
        $user = $this->createEventUser('Employee A');
        $grantId = (string) Str::uuid();
        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-03-31', 10.0, null, 'manual', $grantId));
        $this->bus()->dispatch(new RevokePaidLeaveGrant($user->id, $grantId, $admin->id, 'テスト'));

        return [$user, $grantId];
    }

    /** @param  array<string, mixed>  $summary */
    private function tableRow(array $summary, string $table): array
    {
        foreach ($summary['tables'] as $row) {
            if ($row['table'] === $table) {
                return $row;
            }
        }
        $this->fail("summaryに{$table}が無い");
    }

    private function foreignKeysEnabled(): bool
    {
        return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1;
    }

    public function test_rebuild_restores_a_broken_projection_to_the_value_from_events(): void
    {
        $admin = $this->createEventAdmin();
        [, $grantId] = $this->userWithRevokedGrant($admin);
        $this->assertEqualsWithDelta(0.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);

        // イベントを経ずに壊す(取消済みの付与の残日数を付与日数に戻す)。
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['remaining_days' => 10.0]);

        $summary = (new ReadModelRebuilder)->rebuild(false);

        $this->assertEqualsWithDelta(0.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
        $this->assertFalse($summary['dry_run']);
        $grants = $this->tableRow($summary, 'paid_leave_grants');
        $this->assertTrue($grants['changed']);
        $this->assertSame(1, $grants['before']);
        $this->assertSame(1, $grants['after']);
        $groups = $this->tableRow($summary, 'groups');
        $this->assertFalse($groups['changed']);
        $this->assertSame($groups['before'], $groups['after']);
        $this->assertGreaterThan(0, $groups['before']);
        $this->assertSame(1, $summary['changed_tables']);
        $this->assertTrue($this->foreignKeysEnabled());
    }

    public function test_rebuild_does_not_touch_the_event_store_or_reference_tables(): void
    {
        $admin = $this->createEventAdmin();
        $this->userWithRevokedGrant($admin);
        $before = [];
        $protected = ['stored_events', 'permissions', 'permission_scope_types', 'features', 'system_settings', 'user_credentials', 'snapshots'];
        foreach ($protected as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $this->assertGreaterThan(0, $before['stored_events']);

        (new ReadModelRebuilder)->rebuild(false);

        foreach ($protected as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), $table);
        }
    }

    public function test_target_tables_exclude_the_event_store_reference_masters_and_operational_tables(): void
    {
        $tables = (new ReadModelRebuilder)->existingTables();

        $this->assertNotEmpty($tables);
        foreach (['stored_events', 'snapshots', 'permissions', 'permission_scope_types', 'features', 'system_settings', 'user_credentials',
            'admin_command_runs', 'jobs', 'failed_jobs', 'personal_access_tokens', 'cache', 'cache_locks', 'sessions', 'migrations', 'password_reset_tokens'] as $table) {
            $this->assertNotContains($table, $tables, $table);
        }
        foreach (['users', 'groups', 'memberships', 'permission_role', 'role_assignments', 'paid_leave_grants', 'attendance_days'] as $table) {
            $this->assertContains($table, $tables, $table);
        }
    }

    public function test_usage_allocations_are_not_doubled_by_running_the_rebuild_twice(): void
    {
        $admin = $this->createEventAdmin();
        $user = $this->createEventUser('Employee B');
        $grantId = (string) Str::uuid();
        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2026-04-01', '2028-03-31', 10.0, null, 'manual', $grantId));
        $usageId = $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id, workflowRequestId: null, attendanceDayId: null, usedOn: '2026-08-10', usedDays: 2.0,
            usageType: 'full', paidLeaveRequestId: (string) Str::uuid(), approverUserId: $admin->id,
        ));
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage($user->id, $usageId, $admin->id));

        $snapshot = fn (): array => [
            'allocations' => DB::table('paid_leave_usage_allocations')->orderBy('usage_id')->get(['usage_id', 'grant_id', 'allocated_days'])->map(fn ($r) => [$r->usage_id, $r->grant_id, round((float) $r->allocated_days, 3)])->all(),
            'remaining' => round((float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 3),
            'used' => round((float) DB::table('paid_leave_grants')->where('id', $grantId)->value('used_days'), 3),
        ];
        $first = $snapshot();
        $this->assertCount(1, $first['allocations']);
        $this->assertSame(8.0, $first['remaining']);

        $rebuilder = new ReadModelRebuilder;
        $summary1 = $rebuilder->rebuild(false);
        $afterOnce = $snapshot();
        $summary2 = $rebuilder->rebuild(false);
        $afterTwice = $snapshot();

        $this->assertSame($first, $afterOnce);
        $this->assertSame($first, $afterTwice);
        $this->assertSame(0, $summary1['changed_tables']);
        $this->assertSame(0, $summary2['changed_tables']);
        $allocations = $this->tableRow($summary2, 'paid_leave_usage_allocations');
        $this->assertSame(1, $allocations['after']);
    }

    public function test_dry_run_returns_the_same_summary_but_leaves_the_database_unchanged(): void
    {
        $admin = $this->createEventAdmin();
        [, $grantId] = $this->userWithRevokedGrant($admin);
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['remaining_days' => 10.0]);

        $dry = (new ReadModelRebuilder)->rebuild(true);

        $this->assertTrue($dry['dry_run']);
        $this->assertEqualsWithDelta(10.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
        $this->assertTrue($this->tableRow($dry, 'paid_leave_grants')['changed']);
        $this->assertTrue(DB::table('users')->where('id', $admin->id)->exists());
        $this->assertTrue($this->foreignKeysEnabled());

        $real = (new ReadModelRebuilder)->rebuild(false);
        $this->assertSame($dry['tables'], $real['tables']);
        $this->assertSame($dry['changed_tables'], $real['changed_tables']);
        $this->assertFalse($real['dry_run']);
    }

    public function test_time_limit_of_zero_rolls_back_and_fails(): void
    {
        $admin = $this->createEventAdmin();
        [, $grantId] = $this->userWithRevokedGrant($admin);
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['remaining_days' => 10.0]);
        config(['read_model_rebuild.time_limit_minutes' => 0]);

        try {
            (new ReadModelRebuilder)->rebuild(false);
            $this->fail('例外が出るはず');
        } catch (RuntimeException $e) {
            $this->assertSame(ReadModelRebuilder::TIME_LIMIT_MESSAGE, $e->getMessage());
        }

        $this->assertEqualsWithDelta(10.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
        $this->assertTrue($this->foreignKeysEnabled());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_tables_with_foreign_keys_are_emptied_and_rebuilt_without_failing(): void
    {
        // groups(親)が memberships(子)より先に空にされる並び(テーブル名順)で、子の行が残っている状態から作り直す。
        $admin = $this->createEventAdmin();
        $this->assertGreaterThan(0, DB::table('memberships')->count());
        $tables = (new ReadModelRebuilder)->existingTables();
        $this->assertLessThan(array_search('memberships', $tables, true), array_search('groups', $tables, true));
        $this->assertTrue($this->foreignKeysEnabled());

        $summary = (new ReadModelRebuilder)->rebuild(false);

        $this->assertSame(0, $summary['changed_tables']);
        $this->assertTrue($this->foreignKeysEnabled());
        $this->assertGreaterThan(0, DB::table('memberships')->count());
    }
}
