<?php

namespace Tests\Feature\UserManagement;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\UserManagement\Commands\ApplyExternalHrImport;
use App\Domain\UserManagement\Commands\ChangeFieldAuthority;
use App\Domain\UserManagement\Commands\CompleteOnboardingSsoLink;
use App\Domain\UserManagement\Commands\CompleteOnboardingWithLocalPassword;
use App\Domain\UserManagement\Commands\RecordLocalLogin;
use App\Domain\UserManagement\Commands\RecordSsoLogin;
use App\Domain\UserManagement\Commands\SyncUsersFromMs365;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Graph\MicrosoftGraphClient;
use App\Domain\UserManagement\Graph\MicrosoftGraphUser;
use App\Domain\UserManagement\Handlers\SyncUsersFromMs365Handler;
use App\Domain\UserManagement\Services\StandardGroupMembershipRecorder;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 利用者の表示用データ(users・external_identities)は、作り直した時点のシステム設定を読まず、イベントの値で作られること
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点1)。
 * 作り直しは ReadModelRebuilder の試し実行(changed_tables == 0)で確かめる。データはCommand経由で作る。
 */
class UserProjectionSettingsAtReplayTest extends TestCase
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
        $this->seedAccessControl();
        SystemSetting::current()->update(['default_timezone' => 'Europe/London', 'm365_tenant_id' => 'tenant-A']);
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

    /** 設定を変えてから作り直しの試し実行をし、全表が変わらないことを確かめる。 */
    private function assertRebuildUnchangedAfterSettingsChange(): void
    {
        SystemSetting::current()->update(['default_timezone' => 'America/New_York', 'm365_tenant_id' => 'tenant-B']);

        $summary = (new ReadModelRebuilder)->rebuild(true);

        $changed = array_column(array_filter($summary['tables'], fn (array $row) => $row['changed']), 'table');
        $this->assertSame([], $changed);
        $this->assertSame(0, $summary['changed_tables']);
    }

    /** @return array<string, mixed> */
    private function payload(string $eventClass): array
    {
        return json_decode((string) DB::table('stored_events')->where('event_class', $eventClass)->value('event_properties'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_user_created_manually_keeps_the_recorded_timezone(): void
    {
        $user = $this->createEventUser('Manual User');

        $this->assertSame('Europe/London', $this->payload('user.created_manually')['attributes']['timezone']);
        $this->assertSame('Europe/London', $user->refresh()->timezone);
        $this->assertRebuildUnchangedAfterSettingsChange();
        $this->assertSame('Europe/London', User::query()->findOrFail($user->id)->timezone);
    }

    public function test_user_onboarded_as_admin_by_local_password_keeps_the_recorded_timezone(): void
    {
        $user = $this->bus()->dispatch(new CompleteOnboardingWithLocalPassword('管理者', 'admin@example.com', 'correct-horse-battery-staple'));

        $this->assertSame('Europe/London', $this->payload('user.onboarded_as_admin')['timezone']);
        $this->assertSame('Europe/London', $user->refresh()->timezone);
        $this->assertRebuildUnchangedAfterSettingsChange();
    }

    public function test_user_onboarded_as_admin_by_sso_keeps_the_recorded_timezone_and_tenant_id(): void
    {
        SystemSetting::current()->update(['onboarding_started_at' => now()]);
        $user = $this->bus()->dispatch(new CompleteOnboardingSsoLink('entra-admin', '管理者', 'sso-admin@example.com'));

        $payload = $this->payload('user.onboarded_as_admin');
        $this->assertSame('Europe/London', $payload['timezone']);
        $this->assertSame('tenant-A', $payload['externalTenantId']);
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-admin', 'external_tenant_id' => 'tenant-A']);
        $this->assertRebuildUnchangedAfterSettingsChange();
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-admin', 'external_tenant_id' => 'tenant-A']);
        $this->assertSame('Europe/London', User::query()->findOrFail($user->id)->timezone);
    }

    public function test_user_created_from_sso_login_keeps_the_recorded_timezone_and_tenant_id(): void
    {
        $this->bus()->dispatch(new RecordSsoLogin('entra-sso', 'SSO User', 'sso-user@example.com'));

        $this->assertSame('Europe/London', $this->payload('user.created_from_sso_login')['timezone']);
        $this->assertRebuildUnchangedAfterSettingsChange();
        $this->assertDatabaseHas('users', ['entra_user_id' => 'entra-sso', 'timezone' => 'Europe/London']);
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-sso', 'external_tenant_id' => 'tenant-A']);
    }

    public function test_user_synced_from_ms365_keeps_the_recorded_timezone_and_tenant_id(): void
    {
        $this->syncFromMs365([
            new MicrosoftGraphUser('entra-new', '同期太郎', 'sync@example.com', '開発部', 'エンジニア', true),
        ]);

        $payload = $this->payload('user.synced_from_ms365');
        $this->assertSame('Europe/London', $payload['timezone']);
        $this->assertSame('tenant-A', $payload['externalTenantId']);
        $this->assertDatabaseHas('users', ['entra_user_id' => 'entra-new', 'timezone' => 'Europe/London']);
        $this->assertRebuildUnchangedAfterSettingsChange();
        $this->assertDatabaseHas('users', ['entra_user_id' => 'entra-new', 'timezone' => 'Europe/London']);
        $this->assertDatabaseHas('external_identities', ['external_subject_id' => 'entra-new', 'external_tenant_id' => 'tenant-A']);
    }

    public function test_synced_event_for_an_existing_user_also_records_the_timezone(): void
    {
        $user = $this->createEventUser('Synced Existing');
        DB::table('users')->where('id', $user->id)->update(['entra_user_id' => 'entra-ex']);
        $this->syncFromMs365([new MicrosoftGraphUser('entra-ex', 'Synced Existing', $user->email, null, null, true)]);

        $this->assertSame('Europe/London', $this->payload('user.synced_from_ms365')['timezone']);
    }

    public function test_external_hr_import_records_the_timezone_on_new_rows_only(): void
    {
        foreach (['display_name', 'email'] as $field) {
            $this->bus()->dispatch(new ChangeFieldAuthority($field, 'EXTERNAL_HR', 'csv', null));
        }
        $admin = $this->createEventUser('HR Admin');
        $existing = $this->createEventUser('HR Existing');
        $this->bus()->dispatch(new ApplyExternalHrImport((string) Str::uuid(), [
            ['external_subject_id' => 'hr-new', 'changes' => ['display_name' => 'HR New', 'email' => 'hr-new@example.com']],
            ['external_subject_id' => 'hr-existing', 'user_id' => $existing->id, 'changes' => ['display_name' => 'HR Existing 2']],
        ], $admin->id));

        $rows = $this->payload('external_hr.import_applied')['rows'];
        $this->assertTrue($rows[0]['is_new']);
        $this->assertSame('Europe/London', $rows[0]['timezone']);
        $this->assertFalse($rows[1]['is_new']);
        $this->assertArrayNotHasKey('timezone', $rows[1]);
        $this->assertDatabaseHas('users', ['email' => 'hr-new@example.com', 'timezone' => 'Europe/London']);
        $this->assertRebuildUnchangedAfterSettingsChange();
        $this->assertDatabaseHas('users', ['email' => 'hr-new@example.com', 'timezone' => 'Europe/London']);
    }

    public function test_aggregate_retrieval_works_with_old_events_that_have_no_timezone_key(): void
    {
        $admin = $this->bus()->dispatch(new CompleteOnboardingWithLocalPassword('管理者', 'admin@example.com', 'correct-horse-battery-staple'));
        $manual = $this->createEventUser('Old Manual');
        $this->stripTimezoneKeys();

        $this->bus()->dispatch(new RecordLocalLogin($admin->id));
        $this->bus()->dispatch(new UpdateUserProfile($manual->id, ['name' => 'Old Manual Renamed'], $admin->id));

        $this->assertNotNull($admin->refresh()->last_login_at);
        $this->assertSame('Old Manual Renamed', $manual->refresh()->name);
    }

    public function test_projector_rejects_old_events_without_timezone_when_creating_rows(): void
    {
        $this->bus()->dispatch(new RecordSsoLogin('entra-old', 'Old SSO', 'old-sso@example.com'));
        $this->stripTimezoneKeys();

        try {
            (new ReadModelRebuilder)->rebuild(true);
            $this->fail('未補正のイベントで例外にならなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('未補正', $e->getMessage());
        }
        $this->assertDatabaseHas('users', ['entra_user_id' => 'entra-old']);
    }

    private function stripTimezoneKeys(): void
    {
        foreach (DB::table('stored_events')->whereIn('event_class', ['user.created_manually', 'user.onboarded_as_admin', 'user.created_from_sso_login', 'user.synced_from_ms365'])->get() as $event) {
            $payload = json_decode($event->event_properties, true, 512, JSON_THROW_ON_ERROR);
            unset($payload['timezone'], $payload['attributes']['timezone']);
            DB::table('stored_events')->where('id', $event->id)->update(['event_properties' => json_encode($payload)]);
        }
    }

    /** @param  array<int, MicrosoftGraphUser>  $users */
    private function syncFromMs365(array $users): void
    {
        $client = new class($users) implements MicrosoftGraphClient
        {
            public function __construct(private readonly array $users) {}

            public function listUsers(): iterable
            {
                return $this->users;
            }
        };
        (new SyncUsersFromMs365Handler($client, app(StandardGroupMembershipRecorder::class)))->handle(new SyncUsersFromMs365);
    }
}
