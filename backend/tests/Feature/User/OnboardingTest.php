<?php

namespace Tests\Feature\User;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\CompleteOnboardingSsoLink;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000): SSOモード(実際のEntra ID
 * ログインでの管理者リンク)とローカルパスワードモードの両方を確認する。
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->create(['code' => Role::ADMIN, 'name' => 'システム管理者']);
    }

    public function test_status_reports_needs_onboarding_and_sso_configured(): void
    {
        $this->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJsonPath('needs_onboarding', true)
            ->assertJsonPath('sso_configured', false);
    }

    // --- SSOモード ---

    public function test_sso_onboarding_start_saves_settings_and_returns_a_redirect_url(): void
    {
        $response = $this->startSsoOnboarding();

        $response->assertOk()->assertJsonStructure(['redirect_url']);
        $this->assertStringContainsString('state=onboarding-sso-link', $response->json('redirect_url'));

        $settings = SystemSetting::current();
        $this->assertSame('tenant-1', $settings->m365_tenant_id);
        $this->assertSame('client-1', $settings->m365_client_id);
        $this->assertNotNull($settings->onboarding_started_at);
        $this->assertNull($settings->onboarding_completed_at);

        $this->getJson('/api/onboarding/status')->assertJsonPath('sso_configured', true);
    }

    public function test_sso_onboarding_cannot_start_twice(): void
    {
        $this->startSsoOnboarding()->assertOk();
        $this->startSsoOnboarding()->assertStatus(422);
    }

    public function test_sso_onboarding_can_restart_after_ten_minutes_of_inactivity(): void
    {
        $this->startSsoOnboarding()->assertOk();

        SystemSetting::current()->update(['onboarding_started_at' => now()->subMinutes(11)]);

        $this->startSsoOnboarding()->assertOk();
    }

    public function test_sso_onboarding_link_creates_admin_and_completes_onboarding(): void
    {
        $this->startSsoOnboarding();

        $user = app(CommandBus::class)->dispatch(new CompleteOnboardingSsoLink(
            entraUserId: 'entra-admin-1',
            name: 'テスト管理者',
            email: 'admin@example.com',
        ));

        $this->assertSame('entra-admin-1', $user->entra_user_id);
        $this->assertTrue($user->hasRole(Role::ADMIN));
        $this->assertNotNull(SystemSetting::current()->onboarding_completed_at);
    }

    public function test_sso_onboarding_link_is_rejected_when_not_started(): void
    {
        $this->expectException(DomainRuleException::class);

        app(CommandBus::class)->dispatch(new CompleteOnboardingSsoLink(
            entraUserId: 'entra-admin-1',
            name: 'テスト管理者',
            email: 'admin@example.com',
        ));
    }

    public function test_sso_onboarding_link_is_rejected_when_email_already_registered(): void
    {
        $this->startSsoOnboarding();
        User::factory()->create(['email' => 'existing@example.com']);

        $this->expectException(DomainRuleException::class);

        app(CommandBus::class)->dispatch(new CompleteOnboardingSsoLink(
            entraUserId: 'entra-admin-1',
            name: 'テスト管理者',
            email: 'existing@example.com',
        ));
    }

    // --- ローカルパスワードモード ---

    public function test_local_onboarding_creates_admin_and_returns_a_token(): void
    {
        $response = $this->completeLocalOnboarding();
        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($admin->hasRole(Role::ADMIN));
        $this->assertNull($admin->entra_user_id);
        $this->assertNotNull(SystemSetting::current()->onboarding_completed_at);

        $token = $response->json('token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $admin->id);
    }

    public function test_local_onboarding_cannot_run_twice(): void
    {
        $this->completeLocalOnboarding()->assertOk();
        $this->completeLocalOnboarding()->assertStatus(422);
    }

    public function test_local_onboarding_rejects_an_already_registered_email(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->completeLocalOnboarding()->assertStatus(422);
    }

    public function test_local_login_succeeds_with_the_correct_password(): void
    {
        $this->completeLocalOnboarding();

        $this->postJson('/api/auth/local-login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }

    public function test_local_login_rejects_the_wrong_password(): void
    {
        $this->completeLocalOnboarding();

        $this->postJson('/api/auth/local-login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_local_login_rejects_sso_only_users_without_a_password(): void
    {
        User::factory()->create(['email' => 'sso-user@example.com', 'password' => null]);

        $this->postJson('/api/auth/local-login', [
            'email' => 'sso-user@example.com',
            'password' => 'anything',
        ])->assertStatus(422);
    }

    private function startSsoOnboarding(): TestResponse
    {
        return $this->postJson('/api/onboarding/sso', [
            'm365_tenant_id' => 'tenant-1',
            'm365_client_id' => 'client-1',
            'm365_client_secret' => 'secret-1',
            'm365_redirect_uri' => 'http://localhost:8000/api/auth/microsoft/callback',
        ]);
    }

    private function completeLocalOnboarding(): TestResponse
    {
        return $this->postJson('/api/onboarding/local', [
            'admin_name' => 'テスト管理者',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'correct-horse-battery-staple',
        ]);
    }
}
