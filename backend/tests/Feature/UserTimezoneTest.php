<?php

namespace Tests\Feature;

use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Commands\SyncUsersFromMs365;
use App\Domain\User\Graph\MicrosoftGraphClient;
use App\Domain\User\Graph\MicrosoftGraphUser;
use App\Domain\User\Handlers\SyncUsersFromMs365Handler;
use App\Domain\User\SsoAuthenticator;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Tests\TestCase;

/**
 * UC-003: システム設定のデフォルトタイムゾーンが新規ユーザー作成時に適用され、
 * 既存ユーザーのタイムゾーンには影響しないことを確認する。
 */
class UserTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_sso_first_login_creates_a_user_with_the_default_timezone(): void
    {
        SystemSetting::current()->update(['default_timezone' => 'America/New_York']);
        Role::query()->create(['code' => Role::EMPLOYEE, 'name' => '一般社員']);

        $authenticator = app(SsoAuthenticator::class);
        $ssoUser = $this->fakeSocialiteUser('entra-1', 'テスト太郎', 'taro@example.com');

        $user = $authenticator->handle($ssoUser);

        $this->assertSame('America/New_York', $user->timezone);
    }

    public function test_sso_repeat_login_does_not_change_an_existing_users_timezone(): void
    {
        Role::query()->create(['code' => Role::EMPLOYEE, 'name' => '一般社員']);
        $authenticator = app(SsoAuthenticator::class);
        $ssoUser = $this->fakeSocialiteUser('entra-2', 'テスト花子', 'hanako@example.com');

        $user = $authenticator->handle($ssoUser);
        $user->timezone = 'Europe/London';
        $user->save();

        SystemSetting::current()->update(['default_timezone' => 'America/New_York']);
        $authenticator->handle($ssoUser);

        $this->assertSame('Europe/London', $user->refresh()->timezone);
    }

    public function test_ms365_sync_creates_a_new_user_with_the_default_timezone(): void
    {
        SystemSetting::current()->update(['default_timezone' => 'America/New_York']);

        $handler = new SyncUsersFromMs365Handler(
            new FakeMicrosoftGraphClientForTimezoneTest([
                new MicrosoftGraphUser('entra-3', '同期太郎', 'sync@example.com', '開発部', 'エンジニア', true),
            ]),
            app(EventStore::class),
        );

        $handler->handle(new SyncUsersFromMs365);

        $user = User::query()->where('entra_user_id', 'entra-3')->first();
        $this->assertNotNull($user);
        $this->assertSame('America/New_York', $user->timezone);
    }

    public function test_ms365_sync_does_not_change_an_existing_users_timezone(): void
    {
        $user = User::factory()->create(['entra_user_id' => 'entra-4', 'timezone' => 'Europe/London']);
        SystemSetting::current()->update(['default_timezone' => 'America/New_York']);

        $handler = new SyncUsersFromMs365Handler(
            new FakeMicrosoftGraphClientForTimezoneTest([
                new MicrosoftGraphUser('entra-4', $user->name, $user->email, $user->department, $user->job_title, true),
            ]),
            app(EventStore::class),
        );

        $handler->handle(new SyncUsersFromMs365);

        $this->assertSame('Europe/London', $user->refresh()->timezone);
    }

    public function test_admin_can_view_and_update_the_default_timezone(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->getJson('/api/system-settings')
            ->assertOk()
            ->assertJsonPath('default_timezone', 'Asia/Tokyo');

        $this->actingAs($admin)->putJson('/api/system-settings', ['default_timezone' => 'America/New_York'])
            ->assertOk()
            ->assertJsonPath('default_timezone', 'America/New_York');

        $this->assertSame('America/New_York', SystemSetting::current()->default_timezone);
    }

    public function test_non_admin_cannot_update_the_default_timezone(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->putJson('/api/system-settings', ['default_timezone' => 'America/New_York'])
            ->assertForbidden();
    }

    private function fakeSocialiteUser(string $id, string $name, string $email): SocialiteUser
    {
        return new class($id, $name, $email) implements SocialiteUser
        {
            public function __construct(
                private readonly string $id,
                private readonly string $name,
                private readonly string $email,
            ) {}

            public function getId()
            {
                return $this->id;
            }

            public function getNickname()
            {
                return null;
            }

            public function getName()
            {
                return $this->name;
            }

            public function getEmail()
            {
                return $this->email;
            }

            public function getAvatar()
            {
                return null;
            }
        };
    }
}

class FakeMicrosoftGraphClientForTimezoneTest implements MicrosoftGraphClient
{
    /**
     * @param  array<int, MicrosoftGraphUser>  $users
     */
    public function __construct(private readonly array $users) {}

    public function listUsers(): iterable
    {
        return $this->users;
    }
}
