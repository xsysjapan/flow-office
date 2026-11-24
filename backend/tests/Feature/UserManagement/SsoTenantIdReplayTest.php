<?php

namespace Tests\Feature\UserManagement;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Domain\UserManagement\Projectors\UserProjector;
use App\Models\SystemSetting;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 論点19 決定9: Entra外部IDのテナントIDは、記録時の設定値をイベントに持たせ、再生時に設定を変えても変わらないこと。
 * 旧イベント(externalTenantId の無いもの)は、補正するまで作り直しを拒否し、補正後は記録した値になること。
 */
class SsoTenantIdReplayTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->runRebuildDatabaseMigrations();
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    public function test_sso_login_event_keeps_the_recorded_tenant_id_when_the_setting_changes_before_replay(): void
    {
        $this->seed(UserManagementSeeder::class);
        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-A']);

        app(CommandBus::class)->dispatch(new RecordSsoLogin(
            entraUserId: 'entra-sso-1',
            name: 'SSO User',
            email: 'sso-user@example.test',
        ));

        $this->assertSame('tenant-A', $this->storedPayload('user.created_from_sso_login')['externalTenantId']);
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-sso-1', 'external_tenant_id' => 'tenant-A']);

        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-B']);
        DB::table('external_identities')->where('external_subject_id', 'entra-sso-1')->delete();
        $this->replayUserProjector();

        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-sso-1', 'external_tenant_id' => 'tenant-A']);
    }

    public function test_an_old_event_without_the_key_refuses_the_rebuild_until_it_is_corrected_and_then_keeps_the_recorded_value(): void
    {
        $this->seed(UserManagementSeeder::class);
        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-A']);
        app(CommandBus::class)->dispatch(new RecordSsoLogin(
            entraUserId: 'entra-sso-old',
            name: 'Old User',
            email: 'old-user@example.test',
        ));

        // 旧イベント(timezone・externalTenantId の無いpayload)を再現する。
        $payload = $this->storedPayload('user.created_from_sso_login');
        unset($payload['externalTenantId'], $payload['timezone']);
        DB::table('stored_events')->where('event_class', 'user.created_from_sso_login')->update(['event_properties' => json_encode($payload)]);

        // 補正するまで作り直しを拒否する(設定の値に切り替えて作り直すことはしない)。
        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-B']);
        $this->artisan('read-models:rebuild', ['--dry-run' => true])->expectsOutputToContain('events:fix-user-settings-at-replay')->assertExitCode(1);
        $this->artisan('projections:rebuild')->expectsOutputToContain('events:fix-user-settings-at-replay')->assertExitCode(1);
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-sso-old', 'external_tenant_id' => 'tenant-A']);

        // 補正後は、記録した値(ライブの値 tenant-A)になる。設定を変えても変わらない。
        config(['read_model_rebuild.claim_wait_seconds' => 0]);
        $code = Artisan::call('events:fix-user-settings-at-replay', ['--execute' => true]); $this->assertSame(0, $code, Artisan::output());
        $this->assertSame('tenant-A', $this->storedPayload('user.created_from_sso_login')['externalTenantId']);

        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-C']);
        DB::table('external_identities')->where('external_subject_id', 'entra-sso-old')->delete();
        $this->replayUserProjector();

        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-sso-old', 'external_tenant_id' => 'tenant-A']);
    }

    /** @return array<string, mixed> */
    private function storedPayload(string $eventClass): array
    {
        return json_decode((string) DB::table('stored_events')->where('event_class', $eventClass)->value('event_properties'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function replayUserProjector(): void
    {
        $exitCode = Artisan::call('event-sourcing:replay', ['projector' => [UserProjector::class], '--force' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
