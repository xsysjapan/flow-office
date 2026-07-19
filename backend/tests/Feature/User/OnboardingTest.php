<?php

namespace Tests\Feature\User;

use App\Domain\User\SsoAuthenticator;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Tests\TestCase;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md): Microsoft 365連携設定の登録と
 * 最初の管理者ユーザー作成、およびその後のSSO初回ログインでのリンクを確認する。
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_onboarding_needed_until_completed(): void
    {
        $this->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJsonPath('needs_onboarding', true);

        Role::query()->create(['code' => Role::ADMIN, 'name' => 'システム管理者']);
        $this->completeOnboarding();

        $this->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJsonPath('needs_onboarding', false);
    }

    public function test_onboarding_creates_admin_user_saves_m365_settings_and_returns_a_token(): void
    {
        Role::query()->create(['code' => Role::ADMIN, 'name' => 'システム管理者']);

        $response = $this->completeOnboarding();
        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($admin->hasRole(Role::ADMIN));
        $this->assertNull($admin->entra_user_id);

        $settings = SystemSetting::current();
        $this->assertSame('tenant-1', $settings->m365_tenant_id);
        $this->assertSame('client-1', $settings->m365_client_id);
        $this->assertSame('secret-1', $settings->m365_client_secret);
        $this->assertNotNull($settings->onboarding_completed_at);

        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $admin->id);
    }

    public function test_onboarding_cannot_be_run_twice(): void
    {
        Role::query()->create(['code' => Role::ADMIN, 'name' => 'システム管理者']);

        $this->completeOnboarding()->assertOk();
        $this->completeOnboarding()->assertStatus(422);
    }

    public function test_subsequent_sso_login_links_the_onboarding_admin_by_email_instead_of_duplicating(): void
    {
        Role::query()->create(['code' => Role::ADMIN, 'name' => 'システム管理者']);
        Role::query()->create(['code' => Role::EMPLOYEE, 'name' => '一般社員']);

        $this->completeOnboarding();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $authenticator = app(SsoAuthenticator::class);
        $ssoUser = $this->fakeSocialiteUser('entra-admin-1', 'テスト管理者', 'admin@example.com');
        $loggedInUser = $authenticator->handle($ssoUser);

        $this->assertSame($admin->id, $loggedInUser->id);
        $this->assertSame('entra-admin-1', $loggedInUser->refresh()->entra_user_id);
        $this->assertTrue($loggedInUser->hasRole(Role::ADMIN));
        $this->assertSame(1, User::query()->where('email', 'admin@example.com')->count());
    }

    private function completeOnboarding(): TestResponse
    {
        return $this->postJson('/api/onboarding', [
            'admin_name' => 'テスト管理者',
            'admin_email' => 'admin@example.com',
            'm365_tenant_id' => 'tenant-1',
            'm365_client_id' => 'client-1',
            'm365_client_secret' => 'secret-1',
            'm365_redirect_uri' => 'http://localhost:8000/api/auth/microsoft/callback',
        ]);
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
