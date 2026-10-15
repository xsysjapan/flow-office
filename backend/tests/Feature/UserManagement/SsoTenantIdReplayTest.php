<?php

namespace Tests\Feature\UserManagement;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Domain\UserManagement\Projectors\UserProjector;
use App\Models\SystemSetting;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 論点19 決定9: Entra外部IDのテナントIDは、記録時の設定値をイベントに持たせ、再生時に設定を変えても変わらないこと。
 * 旧イベント(externalTenantId の無いもの)だけは従来どおり再生時の設定値を読むこと。
 */
class SsoTenantIdReplayTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_an_old_event_without_the_key_takes_the_setting_at_replay(): void
    {
        $this->seed(UserManagementSeeder::class);
        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-A']);
        app(CommandBus::class)->dispatch(new RecordSsoLogin(
            entraUserId: 'entra-sso-old',
            name: 'Old User',
            email: 'old-user@example.test',
        ));

        // 旧イベント(externalTenantId の無いpayload)を再現する。
        $payload = $this->storedPayload('user.created_from_sso_login');
        unset($payload['externalTenantId']);
        DB::table('stored_events')->where('event_class', 'user.created_from_sso_login')->update(['event_properties' => json_encode($payload)]);

        SystemSetting::current()->update(['m365_tenant_id' => 'tenant-B']);
        DB::table('external_identities')->where('external_subject_id', 'entra-sso-old')->delete();
        $this->replayUserProjector();

        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-sso-old', 'external_tenant_id' => 'tenant-B']);
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
