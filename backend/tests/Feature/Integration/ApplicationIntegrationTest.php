<?php

namespace Tests\Feature\Integration;

use App\Models\ApplicationIntegration;
use App\Models\IntegrationClientType;
use App\Models\IntegrationScopeType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UC-I001〜UC-I003: 個人API・MCP連携(docs/25-usecases-integrations-mcp.md)。
 */
class ApplicationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_register_a_personal_integration_and_use_its_token(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/users/me/integrations', [
            'client_type' => IntegrationClientType::MCP_CLIENT,
            'client_name' => 'Claude連携',
            'purpose' => '月次勤怠の下書き作成',
            'scopes' => [IntegrationScopeType::ATTENDANCE_SELF_READ, IntegrationScopeType::ATTENDANCE_SELF_CLOCK],
        ]);

        $response->assertCreated();
        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $integration = ApplicationIntegration::query()->firstOrFail();
        $this->assertSame($employee->id, $integration->owner_user_id);
        $this->assertSame('active', $integration->status);

        // 発行されたトークンでスコープの範囲内の操作(打刻)ができる。
        Sanctum::actingAs($employee, [IntegrationScopeType::ATTENDANCE_SELF_READ, IntegrationScopeType::ATTENDANCE_SELF_CLOCK]);
        $this->postJson('/api/attendance/clock-in')->assertSuccessful();
    }

    public function test_a_scope_limited_integration_token_cannot_call_out_of_scope_endpoints(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs($employee, [IntegrationScopeType::ATTENDANCE_SELF_READ]);

        // attendance:self:readしか持たないトークンでは打刻(attendance:self:clock)できない。
        $this->postJson('/api/attendance/clock-in')->assertForbidden();

        // 一般APIも呼べない(EnsureFullAccessOrExplicitAbilityのデフォルト拒否)。
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_an_admins_personal_integration_token_cannot_access_other_users_data(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $other = User::factory()->create();

        // 管理者本人の個人連携トークンであっても、他人の勤怠を閲覧する権限は自動付与しない
        // (docs/25-usecases-integrations-mcp.md UC-I002)。
        Sanctum::actingAs($admin, [IntegrationScopeType::ATTENDANCE_SELF_READ]);

        $this->getJson("/api/attendance/months/2026-07?user_id={$other->id}")->assertForbidden();
    }

    public function test_employee_can_revoke_and_reissue_their_integration(): void
    {
        $employee = User::factory()->create();

        $created = $this->actingAs($employee)->postJson('/api/users/me/integrations', [
            'client_type' => IntegrationClientType::API_CLIENT,
            'client_name' => 'カスタム連携',
            'scopes' => [IntegrationScopeType::SCHEDULE_SELF_READ],
        ])->assertCreated();

        $integrationId = $created->json('integration.id');

        $reissued = $this->actingAs($employee)->postJson("/api/users/me/integrations/{$integrationId}/reissue");
        $reissued->assertSuccessful();
        $this->assertNotEmpty($reissued->json('token'));

        $this->actingAs($employee)->postJson("/api/users/me/integrations/{$integrationId}/revoke")->assertSuccessful();
        $this->assertSame('revoked', ApplicationIntegration::query()->findOrFail($integrationId)->status);
    }

    public function test_employee_cannot_revoke_another_employees_integration(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $created = $this->actingAs($owner)->postJson('/api/users/me/integrations', [
            'client_type' => IntegrationClientType::API_CLIENT,
            'client_name' => '連携',
            'scopes' => [IntegrationScopeType::SCHEDULE_SELF_READ],
        ])->assertCreated();

        $this->actingAs($other)->postJson("/api/users/me/integrations/{$created->json('integration.id')}/revoke")
            ->assertForbidden();
    }

    public function test_registering_with_a_disallowed_scope_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/users/me/integrations', [
            'client_type' => IntegrationClientType::API_CLIENT,
            'client_name' => '不正スコープ連携',
            'scopes' => ['attendance:admin:close_month'],
        ])->assertStatus(422);
    }
}
