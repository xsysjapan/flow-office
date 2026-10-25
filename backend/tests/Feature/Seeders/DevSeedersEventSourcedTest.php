<?php

namespace Tests\Feature\Seeders;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ScenarioSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 開発用シーダー(DatabaseSeeder・ScenarioSeeder)がReadModelへイベントを経ずに書かないことのテスト
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点4)。
 * シーダーの後にReadModelを作り直しても(試し実行)、変わるテーブルが無いこと。
 */
class DevSeedersEventSourcedTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->runRebuildDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRebuildHeartbeat();
        config(['read_model_rebuild.claim_wait_seconds' => 0]);
        // ScenarioSeederは今月を基準にするため、日時を固定する。
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    private function seedAll(bool $mock): void
    {
        config(['services.azure.mock_enabled' => $mock]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(ScenarioSeeder::class);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'role_assignments' => DB::table('role_assignments')->count(),
            'group_feature_assignments' => DB::table('group_feature_assignments')->count(),
        ];
    }

    /** @return array<string, array{bool}> */
    public static function mockProvider(): array
    {
        return ['mock有効' => [true], 'mock無効' => [false]];
    }

    #[DataProvider('mockProvider')]
    public function test_シーダーの後の作り直しの試し実行で変更のあるテーブルが無い(bool $mock): void
    {
        $this->seedAll($mock);

        $summary = (new ReadModelRebuilder)->rebuild(true);

        $changed = collect($summary['tables'])->filter(fn (array $row) => $row['changed'])
            ->map(fn (array $row) => "{$row['table']}: {$row['before']} -> {$row['after']}")->values()->all();
        $this->assertSame(0, $summary['changed_tables'], '変更のあるテーブル: '.implode(', ', $changed));
    }

    #[DataProvider('mockProvider')]
    public function test_同じシーダーを2回流しても失敗せず行が増えない(bool $mock): void
    {
        $this->seedAll($mock);
        $first = $this->counts();

        $this->seedAll($mock);

        $this->assertSame($first, $this->counts());
    }

    public function test_管理者の作成イベントがあり_mock有効時はmock_entra_adminと連携される(): void
    {
        $this->seedAll(true);

        $adminId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'seed-user:admin@example.com')->toString();
        $admin = DB::table('users')->where('email', 'admin@example.com')->first();
        $this->assertSame($adminId, $admin->id);
        $this->assertSame('mock-entra-admin', $admin->entra_user_id);
        $this->assertTrue(DB::table('stored_events')->where('aggregate_uuid', $adminId)->where('event_class', 'user.created_manually')->exists());
        $this->assertTrue(DB::table('stored_events')->where('aggregate_uuid', $adminId)->where('event_class', 'like', '%sso%')->exists());
    }

    public function test_mock無効でもScenarioSeederの後は管理者がmock_entra_adminと連携される(): void
    {
        $this->seedAll(false);

        $this->assertSame('mock-entra-admin', DB::table('users')->where('email', 'admin@example.com')->value('entra_user_id'));
    }

    public function test_mock無効でDatabaseSeederだけなら管理者は未連携(): void
    {
        config(['services.azure.mock_enabled' => false]);
        $this->seed(DatabaseSeeder::class);

        $this->assertNull(DB::table('users')->where('email', 'admin@example.com')->value('entra_user_id'));
    }
}
